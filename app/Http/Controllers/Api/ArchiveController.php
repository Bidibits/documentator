<?php

namespace App\Http\Controllers\Api;

use App\Auth\Access;
use App\Auth\SessionUser;
use App\Documents\Archive;
use App\Documents\ArchiveRules;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\DocumentFile;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Support\ChangeLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ArchiveController extends Controller
{
    /**
     * One page of the versions that can be archived or already are (never a current one), the most recently opened
     * first and the never-opened last. One version per row: its document, how many files and how big, when it was last
     * viewed or downloaded, and whether it is archived. q narrows by words (code, title, "Ver 2", note) and filter is
     * all, live or archived; the summary always counts everything this person may archive.
     */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'filter' => ['nullable', Rule::in(['all', 'live', 'archived'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per' => ['nullable', 'integer', Rule::in([25, 50, 100])],
        ]);
        $grants = Access::grants($this->user($request));
        $scoped = fn () => tap(DocumentVersion::query()
            ->join('documents as d', 'd.id', '=', 'document_versions.document_id')
            ->whereColumn('document_versions.id', '!=', 'd.current_version_id'),
            fn ($q) => $grants->scope($q, 'd.department_id', Access::TAGS['archive']));   // only the departments this person may archive in

        $query = $scoped();
        match ($f['filter'] ?? 'all') {
            'live' => $query->whereNull('document_versions.archived_at'),
            'archived' => $query->whereNotNull('document_versions.archived_at'),
            default => null,
        };
        foreach (preg_split('/\s+/', trim((string) ($f['q'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $like = '%'.addcslashes($word, '\\%_').'%';
            $query->where(fn ($w) => $w->whereRaw("d.code like ? escape '\\'", [$like])->orWhereRaw("d.title like ? escape '\\'", [$like])
                ->orWhereRaw("document_versions.change_note like ? escape '\\'", [$like])->orWhereRaw("('ver ' || document_versions.rev_no) like ? escape '\\'", [$like]));
        }

        $per = (int) ($f['per'] ?? 50);
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / $per));
        $page = min((int) ($f['page'] ?? 1), $pages);

        // the files' counts, sizes and latest uses come from one grouped pass, not from sub-queries run for every version
        $files = DB::table('document_files')->selectRaw('version_id, count(*) as files_count, coalesce(sum(size), 0) as files_size, max(last_viewed_at) as last_viewed_at, max(last_downloaded_at) as last_downloaded_at')->groupBy('version_id');
        $versions = $query
            ->leftJoinSub($files, 'fa', 'fa.version_id', '=', 'document_versions.id')
            ->select('document_versions.*', 'd.code as document_code', 'd.title as document_title', 'fa.files_count', 'fa.files_size', 'fa.last_viewed_at', 'fa.last_downloaded_at')
            ->with('archiver')
            // newest access first, never-opened last; the newer version first among equals
            ->orderByRaw('case when fa.last_viewed_at is null and fa.last_downloaded_at is null then 1 else 0 end')
            ->orderByRaw("case when coalesce(fa.last_viewed_at, '') >= coalesce(fa.last_downloaded_at, '') then coalesce(fa.last_viewed_at, '') else coalesce(fa.last_downloaded_at, '') end desc")
            ->orderByDesc('document_versions.created_at')->orderByDesc('document_versions.id')
            ->forPage($page, $per)->get();

        $archivedIds = $scoped()->whereNotNull('document_versions.archived_at')->select('document_versions.id');

        return response()->json([
            'versions' => $versions->map(fn (DocumentVersion $v) => $this->row($v))->values()->all(),
            'page' => $page, 'pages' => $pages, 'total' => $total, 'per' => $per,
            'summary' => [
                'versions' => $scoped()->count(),
                'archived' => (clone $archivedIds)->count(),
                'archivedOriginalBytes' => (int) DocumentFile::whereIn('version_id', (clone $archivedIds))->sum('size'),
                'archivedZipBytes' => (int) $scoped()->whereNotNull('document_versions.archived_at')->sum('document_versions.archive_size'),
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
            'dueTotal' => $due['idle']->merge($due['old'])->unique()->count(),
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
