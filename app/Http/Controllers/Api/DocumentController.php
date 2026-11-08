<?php

namespace App\Http\Controllers\Api;

use App\Auth\SessionUser;
use App\Documents\CodeSuggestion;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\ChangeLog;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DocumentController extends Controller
{
    /** The columns of a new document that go into the change log (its dates are added as typed). */
    private const LOGGED_DOCUMENT_COLUMNS = ['code', 'title', 'doc_type', 'department', 'description', 'location_note', 'current_version_id', 'created_by', 'updated_by'];

    /** The register: newest first, plus the departments and types already in use (the form offers them). */
    public function index(): JsonResponse
    {
        $documents = Document::withAccessDates()->with(['currentVersion.files', 'creator'])->orderByDesc('id')->get();

        return response()->json([
            'documents' => $documents->map->view()->all(),
            'departments' => $this->distinct('department'),
            'types' => $this->distinct('doc_type'),
        ]);
    }

    public function suggestCode(): JsonResponse
    {
        return response()->json(['code' => CodeSuggestion::next()]);
    }

    public function show(Document $document): JsonResponse
    {
        $document->load(Document::DETAIL);

        return response()->json(['document' => $document->view(true)]);
    }

    /**
     * Registers a document with its first version (Ver 0) and one or more files, as one multipart form.
     */
    public function store(Request $request): JsonResponse
    {
        $request->merge(['code' => trim((string) $request->input('code'))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'title' => ['required', 'string', 'max:200'],
            'doc_type' => ['nullable', 'string', 'max:64'],
            'department' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issued_on'],
            'location_note' => ['nullable', 'string', 'max:200'],
        ] + $this->fileRules());

        $user = SessionUser::resolve($request);
        $stored = [];

        try {
            $document = DB::transaction(function () use ($data, $user, &$stored) {
                $document = Document::create([
                    'code' => $data['code'],
                    'title' => $data['title'],
                    'doc_type' => $data['doc_type'] ?? null,
                    'department' => $data['department'] ?? null,
                    'description' => $data['description'] ?? null,
                    'issued_on' => $data['issued_on'] ?? null,
                    'expires_on' => $data['expires_on'] ?? null,
                    'location_note' => $data['location_note'] ?? null,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);

                $version = DocumentVersion::create([
                    'document_id' => $document->id,
                    'rev_no' => 0,
                    'created_by' => $user->id,
                ]);
                ChangeLog::created('document_versions', $version->id, ['document_id' => $document->id, 'rev_no' => 0, 'created_by' => $user->id], $user);

                foreach ($data['files'] as $order => $upload) {
                    $stored[] = $this->keep($version, $upload, $order, $user);
                }

                $document->update(['current_version_id' => $version->id]);

                ChangeLog::created('documents', $document->id, Arr::only($document->getAttributes(), self::LOGGED_DOCUMENT_COLUMNS) + ['issued_on' => $data['issued_on'] ?? null, 'expires_on' => $data['expires_on'] ?? null], $user);

                return $document;
            });
        } catch (UniqueConstraintViolationException) {
            $this->discard($stored);
            $suggestion = CodeSuggestion::looksSuggested($data['code']) ? CodeSuggestion::next() : null;
            throw new ApiError(409, 'code_taken', $suggestion === null ? [] : ['suggestion' => $suggestion]);
        } catch (Throwable $e) {
            $this->discard($stored);
            throw $e;
        }

        AuditLog::record($user, 'document.created', $document->title, entityType: 'document', entityId: $document->code);

        $document->load(Document::DETAIL);

        return response()->json(['document' => $document->view(true)], 201);
    }

    /**
     * Adds a new version (Ver 1, 2, ...) of a document: one or more files and an optional note on what changed. The
     * earlier versions and their files stay as they were; the new one becomes the current version.
     */
    public function storeVersion(Request $request, Document $document): JsonResponse
    {
        $data = $request->validate([
            'change_note' => ['nullable', 'string', 'max:500'],
        ] + $this->fileRules());

        $user = SessionUser::resolve($request);
        $stored = [];

        try {
            $number = DB::transaction(function () use ($data, $document, $user, &$stored) {
                $number = (int) DocumentVersion::where('document_id', $document->id)->max('rev_no') + 1;

                $version = DocumentVersion::create([
                    'document_id' => $document->id,
                    'rev_no' => $number,
                    'change_note' => $data['change_note'] ?? null,
                    'created_by' => $user->id,
                ]);
                ChangeLog::created('document_versions', $version->id, ['document_id' => $document->id, 'rev_no' => $number, 'change_note' => $data['change_note'] ?? null, 'created_by' => $user->id], $user);

                foreach ($data['files'] as $order => $upload) {
                    $stored[] = $this->keep($version, $upload, $order, $user);
                }

                $before = Arr::only($document->getAttributes(), ['current_version_id', 'updated_by']);
                $document->update(['current_version_id' => $version->id, 'updated_by' => $user->id]);
                ChangeLog::updated('documents', $document->id, $before, ['current_version_id' => $version->id, 'updated_by' => $user->id], $user);

                return $number;
            });
        } catch (UniqueConstraintViolationException) {
            // someone else added a version at the same moment and took this number
            $this->discard($stored);
            throw new ApiError(409, 'version_conflict');
        } catch (Throwable $e) {
            $this->discard($stored);
            throw $e;
        }

        AuditLog::record($user, 'version.created', 'Ver '.$number, entityType: 'document', entityId: $document->code);

        $document->load(Document::DETAIL);

        return response()->json(['document' => $document->view(true)], 201);
    }

    /** @return array<string, array<int, mixed>> the rules for the files of a registration or a new version */
    private function fileRules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:'.config('documentator.uploads.max_files')],
            'files.*' => ['file', 'max:'.config('documentator.uploads.max_kilobytes'), $this->allowedType()],
        ];
    }

    /**
     * Stores one upload under a name the server makes up (never the visitor's), and records what it is.
     *
     * @return string the storage path, so a failed save can remove it
     */
    private function keep(DocumentVersion $version, UploadedFile $upload, int $order, User $user): string
    {
        $extension = $this->extensionOf($upload);
        // stored as what its extension says it is (the content was already checked to fit): a docx the detector
        // called "zip" is still a docx
        $mime = config("documentator.uploads.types.{$extension}.0");
        $sha256 = hash_file('sha256', $upload->getRealPath());
        $path = $upload->storeAs('documents/'.$version->document_id, Str::uuid().'.'.$extension, 'local');
        if ($path === false) {
            throw new ApiError(500, 'storage');
        }

        $file = DocumentFile::create([
            'version_id' => $version->id,
            'original_name' => $this->cleanName($upload->getClientOriginalName(), $extension),
            'storage_path' => $path,
            'mime' => $mime,
            'size' => $upload->getSize(),
            'sha256' => $sha256,
            'sort_order' => $order,
        ]);
        ChangeLog::created('document_files', $file->id, Arr::only($file->getAttributes(), ['version_id', 'original_name', 'mime', 'size', 'sha256', 'sort_order']), $user);

        return $path;
    }

    /** @param  list<string>  $paths */
    private function discard(array $paths): void
    {
        foreach ($paths as $path) {
            Storage::disk('local')->delete($path);
        }
    }

    /** The upload must really be one of the allowed kinds, and its name must end the way that kind does. */
    private function allowedType(): Closure
    {
        return function (string $attribute, mixed $file, Closure $fail): void {
            if (! $file instanceof UploadedFile || $this->extensionOf($file) === null) {
                $fail('type');
            }
        };
    }

    /** The file's extension when it is an allowed one and the content fits it, else null. */
    private function extensionOf(UploadedFile $file): ?string
    {
        $extension = Str::lower($file->getClientOriginalExtension());
        $allowed = config("documentator.uploads.types.{$extension}");

        return $allowed !== null && in_array((string) $file->getMimeType(), $allowed[1], true) ? $extension : null;
    }

    /** The name as shown and offered for download: no folders, no control characters, not too long. */
    private function cleanName(string $name, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '';
        $name = trim(Str::limit($name, 150, ''));

        return $name === '' || $name === '.'.$extension ? 'file.'.$extension : $name;
    }

    /** @return list<string> */
    private function distinct(string $column): array
    {
        return Document::query()->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->orderBy($column)->pluck($column)->all();
    }
}
