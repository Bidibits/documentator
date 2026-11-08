<?php

namespace App\Http\Controllers\Api;

use App\Auth\SessionUser;
use App\Documents\Archive;
use App\Http\Controllers\Controller;
use App\Models\DocumentVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ArchiveController extends Controller
{
    /**
     * The versions that can be archived or already are (never a current one), the most recently opened first and the
     * never-opened last. One version per row: its document, how many files and how big, when it was last viewed or
     * downloaded, and whether it is archived.
     */
    public function index(): JsonResponse
    {
        $versions = DocumentVersion::query()
            ->join('documents as d', 'd.id', '=', 'document_versions.document_id')
            ->whereColumn('document_versions.id', '!=', 'd.current_version_id')
            ->select('document_versions.*', 'd.code as document_code', 'd.title as document_title')
            ->with('archiver')
            ->withCount('files')
            ->withSum('files as files_size', 'size')
            ->withMax('files as last_viewed_at', 'last_viewed_at')
            ->withMax('files as last_downloaded_at', 'last_downloaded_at')
            ->get();

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

    public function archive(Request $request, DocumentVersion $version): JsonResponse
    {
        Archive::archive($version, $this->user($request));

        return response()->json(['version' => $this->one($version->id)]);
    }

    public function restore(Request $request, DocumentVersion $version): JsonResponse
    {
        Archive::restore($version, $this->user($request));

        return response()->json(['version' => $this->one($version->id)]);
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
            'archivedBy' => $v->archiver?->name ?? '',
            'archiveSize' => (int) $v->archive_size,
        ];
    }
}
