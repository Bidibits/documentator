<?php

namespace App\Http\Controllers\Api;

use App\Auth\Access;
use App\Auth\Grants;
use App\Auth\SessionUser;
use App\Documents\CodeSuggestion;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\Department;
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
use Illuminate\Validation\Rule;
use Throwable;

class DocumentController extends Controller
{
    /** The columns of a new document that go into the change log (its dates are added as typed). */
    private const LOGGED_DOCUMENT_COLUMNS = ['code', 'title', 'doc_type', 'department_id', 'description', 'location_note', 'current_version_id', 'created_by', 'updated_by'];

    /** The register: newest first, plus the departments to choose from and the types already in use (the form offers them). */
    public function index(Request $request): JsonResponse
    {
        $grants = Access::grants(SessionUser::resolve($request));
        $query = Document::withAccessDates()->with(['currentVersion.files', 'creator', 'department']);
        $grants->scope($query, 'documents.department_id', Access::TAGS['view']);   // only the departments this person may see
        $documents = $query->orderByDesc('id')->get();

        return response()->json([
            'documents' => $documents->map(fn (Document $d) => $d->view(false, $grants))->all(),
            // the departments this person may register a document in (switched-off ones are not offered)
            'departments' => Department::query()->where('active', true)->orderBy('name')->get(['id', 'name'])
                ->filter(fn ($d) => $grants->can(Access::TAGS['register'], $d->id))
                ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values()->all(),
            'canRegisterNone' => $grants->can(Access::TAGS['register'], null),
            // the departments this person may move a document into when correcting its details (needs the Update tag there)
            'editDepartments' => Department::query()->where('active', true)->orderBy('name')->get(['id', 'name'])
                ->filter(fn ($d) => $grants->can(Access::TAGS['update'], $d->id))
                ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->values()->all(),
            'canEditNone' => $grants->can(Access::TAGS['update'], null),
            'types' => $this->distinct('doc_type', $grants),
        ]);
    }

    public function suggestCode(): JsonResponse
    {
        return response()->json(['code' => CodeSuggestion::next()]);
    }

    public function show(Request $request, Document $document): JsonResponse
    {
        $grants = Access::grants(SessionUser::resolve($request));
        if (! $grants->can(Access::TAGS['view'], $document->department_id)) {
            throw ApiError::forbidden();
        }
        $document->load(Document::DETAIL);

        return response()->json(['document' => $document->view(true, $grants)]);
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
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('active', true)],
            'description' => ['nullable', 'string', 'max:2000'],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issued_on'],
            'location_note' => ['nullable', 'string', 'max:200'],
        ] + $this->fileRules());

        $user = SessionUser::resolve($request);
        $grants = Access::grants($user);
        if (! $grants->can(Access::TAGS['register'], $data['department_id'] ?? null)) {
            throw ApiError::forbidden();   // not a department (or "no department") this person may register in
        }
        $stored = [];

        try {
            $document = DB::transaction(function () use ($data, $user, &$stored) {
                $document = Document::create([
                    'code' => $data['code'],
                    'title' => $data['title'],
                    'doc_type' => $data['doc_type'] ?? null,
                    'department_id' => $data['department_id'] ?? null,
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
                    'issued_on' => $data['issued_on'] ?? null,
                    'expires_on' => $data['expires_on'] ?? null,
                    'created_by' => $user->id,
                ]);
                ChangeLog::created('document_versions', $version->id, ['document_id' => $document->id, 'rev_no' => 0, 'issued_on' => $data['issued_on'] ?? null, 'expires_on' => $data['expires_on'] ?? null, 'created_by' => $user->id], $user);

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

        return response()->json(['document' => $document->view(true, $grants)], 201);
    }

    /**
     * Corrects a document's details after registering: title, type, department, description, the two dates and the
     * physical location. The code stays as registered (it is what is written on the paper), and the files and versions are
     * untouched (a new file is a new version). Needs the Update tag in the document's department, and in the new one when
     * the document moves. The dates are also corrected on the current version, which carries them. Only the fields sent
     * change; each changed column is logged.
     */
    public function update(Request $request, Document $document): JsonResponse
    {
        $user = SessionUser::resolve($request);
        $grants = Access::grants($user);
        if (! $grants->can(Access::TAGS['update'], $document->department_id)) {
            throw ApiError::forbidden();
        }

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'doc_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'department_id' => ['sometimes', 'nullable', 'integer', Rule::exists('departments', 'id')],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'issued_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'location_note' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        if (isset($data['title']) && trim($data['title']) === '') {
            throw new ApiError(422, 'invalid', ['field' => 'title']);
        }
        if (isset($data['department_id'])) {
            $data['department_id'] = (int) $data['department_id'];   // a form sends it as text
        }

        if (array_key_exists('department_id', $data) && $data['department_id'] !== $document->department_id) {
            $to = $data['department_id'];
            if (! $grants->can(Access::TAGS['update'], $to)) {
                throw ApiError::forbidden();   // may not put documents into that department
            }
            if ($to !== null && ! Department::query()->whereKey($to)->where('active', true)->exists()) {
                throw new ApiError(422, 'invalid', ['field' => 'department_id']);   // switched off: not offered to new arrivals
            }
        }

        $before = $this->editable($document);
        $after = $data + $before;
        $after = array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value), $after);
        if ($after['issued_on'] !== null && $after['expires_on'] !== null && $after['expires_on'] < $after['issued_on']) {
            throw new ApiError(422, 'invalid', ['field' => 'expires_on']);
        }
        $after['updated_by'] = $user->id;

        DB::transaction(function () use ($document, $before, $after, $user) {
            $changes = Arr::except($after, ['updated_by']);
            if ($changes === Arr::except($before, ['updated_by'])) {
                return;   // nothing differs: nothing is saved or logged
            }
            $document->update($after);
            ChangeLog::updated('documents', $document->id, $before, $after, $user);

            // the current version carries the dates, so a corrected date is corrected there too
            $datesChanged = $before['issued_on'] !== $after['issued_on'] || $before['expires_on'] !== $after['expires_on'];
            if ($datesChanged && $document->current_version_id !== null) {
                $version = DocumentVersion::find($document->current_version_id);
                $dates = ['issued_on' => $after['issued_on'], 'expires_on' => $after['expires_on']];
                $was = ['issued_on' => $version->issued_on?->format('Y-m-d'), 'expires_on' => $version->expires_on?->format('Y-m-d')];
                $version->update($dates);
                ChangeLog::updated('document_versions', $version->id, $was, $dates, $user);
            }
        });

        AuditLog::record($user, 'document.updated', $document->title, entityType: 'document', entityId: $document->code);

        $document->refresh()->load(Document::DETAIL);

        return response()->json(['document' => $document->view(true, $grants)]);
    }

    /** @return array<string, mixed> the columns a person may correct, as they are now (dates as typed) */
    private function editable(Document $document): array
    {
        return [
            'title' => $document->title,
            'doc_type' => $document->doc_type,
            'department_id' => $document->department_id,
            'description' => $document->description,
            'issued_on' => $document->issued_on?->format('Y-m-d'),
            'expires_on' => $document->expires_on?->format('Y-m-d'),
            'location_note' => $document->location_note,
            'updated_by' => $document->updated_by,
        ];
    }

    /**
     * Adds a new version (Ver 1, 2, ...) of a document: one or more files and an optional note on what changed. The
     * earlier versions and their files stay as they were; the new one becomes the current version.
     */
    public function storeVersion(Request $request, Document $document): JsonResponse
    {
        $user = SessionUser::resolve($request);
        $grants = Access::grants($user);
        if (! $grants->can(Access::TAGS['update'], $document->department_id)) {
            throw ApiError::forbidden();
        }

        $data = $request->validate([
            'change_note' => ['nullable', 'string', 'max:500'],
            // a renewal: the new version carries new dates, and the document moves on to them
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
        ] + $this->fileRules());

        $renewal = $request->has('issued_on') || $request->has('expires_on');
        $issued = $renewal ? ($data['issued_on'] ?? null) : $document->issued_on?->format('Y-m-d');
        $expires = $renewal ? ($data['expires_on'] ?? null) : $document->expires_on?->format('Y-m-d');
        if ($renewal && $issued !== null && $expires !== null && $expires < $issued) {
            throw new ApiError(422, 'invalid', ['field' => 'expires_on']);
        }

        $stored = [];

        try {
            $number = DB::transaction(function () use ($data, $document, $user, $renewal, $issued, $expires, &$stored) {
                $number = (int) DocumentVersion::where('document_id', $document->id)->max('rev_no') + 1;

                // the version keeps the dates it was valid for; a version that is not a renewal carries the document's dates on
                $version = DocumentVersion::create([
                    'document_id' => $document->id,
                    'rev_no' => $number,
                    'change_note' => $data['change_note'] ?? null,
                    'issued_on' => $issued,
                    'expires_on' => $expires,
                    'created_by' => $user->id,
                ]);
                ChangeLog::created('document_versions', $version->id, ['document_id' => $document->id, 'rev_no' => $number, 'change_note' => $data['change_note'] ?? null, 'issued_on' => $issued, 'expires_on' => $expires, 'created_by' => $user->id], $user);

                foreach ($data['files'] as $order => $upload) {
                    $stored[] = $this->keep($version, $upload, $order, $user);
                }

                $before = Arr::only($document->getAttributes(), ['current_version_id', 'updated_by']) + ['issued_on' => $document->issued_on?->format('Y-m-d'), 'expires_on' => $document->expires_on?->format('Y-m-d')];
                $changes = ['current_version_id' => $version->id, 'updated_by' => $user->id];
                if ($renewal) {
                    $changes += ['issued_on' => $issued, 'expires_on' => $expires];
                }
                $document->update($changes);
                ChangeLog::updated('documents', $document->id, $before, $changes, $user);

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

        AuditLog::record($user, $renewal ? 'document.renewed' : 'version.created', 'Ver '.$number, entityType: 'document', entityId: $document->code);

        $document->load(Document::DETAIL);

        return response()->json(['document' => $document->view(true, $grants)], 201);
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
    private function distinct(string $column, Grants $grants): array
    {
        $query = Document::query()->whereNotNull($column)->where($column, '!=', '');
        $grants->scope($query, 'documents.department_id', Access::TAGS['view']);

        return $query->distinct()->orderBy($column)->pluck($column)->all();
    }
}
