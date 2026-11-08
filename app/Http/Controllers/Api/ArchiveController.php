<?php

namespace App\Http\Controllers\Api;

use App\Auth\Access;
use App\Auth\SessionUser;
use App\Documents\Archive;
use App\Documents\ArchiveRules;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Support\ChangeLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ArchiveController extends Controller
{
    /**
     * The versions that can be archived or already are (never a current one), the most recently opened first and the
     * never-opened last. One version per row: its document, how many files and how big, when it was last viewed or
     * downloaded, and whether it is archived.
     */
    public function index(Request $request): JsonResponse
    {
        $grants = Access::grants($this->user($request));
        $query = DocumentVersion::query()
            ->join('documents as d', 'd.id', '=', 'document_versions.document_id')
            ->whereColumn('document_versions.id', '!=', 'd.current_version_id')
            ->select('document_versions.*', 'd.code as document_code', 'd.title as document_title')
            ->with('archiver')
            ->withCount('files')
            ->withSum('files as files_size', 'size')
            ->withMax('files as last_viewed_at', 'last_viewed_at')
            ->withMax('files as last_downloaded_at', 'last_downloaded_at');
        $grants->scope($query, 'd.department_id', Access::TAGS['archive']);   // only the departments this person may archive in
        $versions = $query->get();

        $rows = $versions->map(fn (DocumentVersion $v) => $this->row($v))->sort(function (array $a, array $b) {
            // newest access first, never-opened last; the newer version first among equals
            return [$b['lastAccessedAt'] !== '', $b['lastAccessedAt'], $b['createdAt'], $b['id']]
                <=> [$a['lastAccessedAt'] !== '', $a['lastAccessedAt'], $a['createdAt'], $a['id']];
        })->values();

        $archived = $rows->where('archived', true);

        return response()->json([
            'versions' => $rows->all(),
            'summary' => [
                'versions' => $rows->count(),
                'archived' => $archived->count(),
                'archivedOriginalBytes' => (int) $archived->sum('size'),
                'archivedZipBytes' => (int) $archived->sum('archiveSize'),
            ],
        ]);
    }

    /** Both rules, how many versions each would archive right now, and what the last run did. */
    public function rules(): JsonResponse
    {
        $due = ArchiveRules::due();
        $last = DB::table('audit_logs')->where('action', 'archive.run')->orderByDesc('id')->first(['occurred_at', 'actor_username', 'note']);

        return response()->json([
            'rules' => collect(ArchiveRules::all())->map(fn (array $rule, string $kind) => $rule + ['due' => $due[$kind]->count()])->values()->all(),
            'dueTotal' => $due['idle']->merge($due['old'])->unique('id')->count(),
            'lastRun' => $last === null ? null : [
                'at' => User::stamp(Carbon::parse($last->occurred_at, 'UTC')),
                'by' => $last->actor_username === 'system' ? 'the nightly run' : $last->actor_username,
                'note' => $last->note,
            ],
            'schedule' => 'every night at 02:00 ('.config('documentator.timezone').')',
        ]);
    }

    public function saveRule(Request $request, string $kind): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'amount' => ['required', 'integer'],
            'unit' => ['required', 'string'],
        ]);
        ArchiveRules::save($kind, (bool) $data['enabled'], (int) $data['amount'], $data['unit'], $this->user($request));

        return $this->rules();
    }

    /** Runs the rules now, as the signed-in person (the nightly run does the same as the system). */
    public function run(Request $request): JsonResponse
    {
        return response()->json(ArchiveRules::run($this->user($request), ChangeLog::MANUAL));
    }

    public function archive(Request $request, DocumentVersion $version): JsonResponse
    {
        $this->mayArchive($request, $version);
        Archive::archive($version, $this->user($request));

        return response()->json(['version' => $this->one($version->id)]);
    }

    public function restore(Request $request, DocumentVersion $version): JsonResponse
    {
        $this->mayArchive($request, $version);
        Archive::restore($version, $this->user($request));

        return response()->json(['version' => $this->one($version->id)]);
    }

    /** The person must be allowed to archive in this document's department. */
    private function mayArchive(Request $request, DocumentVersion $version): void
    {
        if (! Access::grants($this->user($request))->can(Access::TAGS['archive'], $version->document->department_id)) {
            throw ApiError::forbidden();
        }
    }

    private function user(Request $request): User
    {
        return SessionUser::resolve($request);
    }

    private function one(int $id): array
    {
        $version = DocumentVersion::query()
            ->join('documents as d', 'd.id', '=', 'document_versions.document_id')
            ->where('document_versions.id', $id)
            ->select('document_versions.*', 'd.code as document_code', 'd.title as document_title')
            ->with('archiver')->withCount('files')->withSum('files as files_size', 'size')
            ->withMax('files as last_viewed_at', 'last_viewed_at')->withMax('files as last_downloaded_at', 'last_downloaded_at')
            ->firstOrFail();

        return $this->row($version);
    }

    /** @return array<string, mixed> */
    private function row(DocumentVersion $v): array
    {
        $stamp = fn (?string $value) => $value === null ? '' : User::stamp(Carbon::parse($value, 'UTC'));
        $viewed = $stamp($v->getAttributes()['last_viewed_at'] ?? null);
        $downloaded = $stamp($v->getAttributes()['last_downloaded_at'] ?? null);

        return [
            'id' => $v->id,
            'documentId' => $v->document_id,
            'code' => $v->getAttributes()['document_code'],
            'title' => $v->getAttributes()['document_title'],
            'rev' => $v->rev_no,
            'changeNote' => (string) $v->change_note,
            'files' => (int) $v->files_count,
            'size' => (int) ($v->getAttributes()['files_size'] ?? 0),
            'createdAt' => User::stamp($v->created_at),
            'lastViewedAt' => $viewed,
            'lastDownloadedAt' => $downloaded,
            'lastAccessedAt' => max($viewed, $downloaded),   // the stamps sort as text
            'archived' => $v->isArchived(),
            'archivedAt' => User::stamp($v->archived_at),
            'archivedBy' => $v->archiver?->name ?? ($v->isArchived() ? 'an archive rule' : ''),
            'archiveSize' => (int) $v->archive_size,
        ];
    }
}
