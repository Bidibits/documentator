<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'code', 'title', 'doc_type', 'department_id', 'description', 'issued_on', 'expires_on', 'location_note',
    'current_version_id', 'created_by', 'updated_by',
])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'expires_on' => 'date'];
    }

    /** What the drawer needs loaded to show a whole document. */
    public const DETAIL = [
        'currentVersion.files', 'creator', 'department', 'versions.creator',
        'versions.files.viewer', 'versions.files.downloader', 'versions.archiver',
    ];

    /**
     * Adds last_viewed_at and last_downloaded_at: the latest of any file of any version (the table shows them).
     */
    public function scopeWithAccessDates(Builder $query): void
    {
        $latest = fn (string $column) => DB::table('document_files as f')
            ->join('document_versions as v', 'v.id', '=', 'f.version_id')
            ->whereColumn('v.document_id', 'documents.id')
            ->selectRaw("max(f.{$column})");

        $query->select('documents.*')->addSelect([
            'last_viewed_at' => $latest('last_viewed_at'),
            'last_downloaded_at' => $latest('last_downloaded_at'),
            'archived_versions' => DB::table('document_versions as av')->whereColumn('av.document_id', 'documents.id')
                ->whereNotNull('av.archived_at')->selectRaw('count(*)'),
        ]);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('rev_no');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** A column added by withAccessDates (stored as UTC text), as the browser reads timestamps; empty when never. */
    private function access(string $column): string
    {
        $value = $this->getAttributes()[$column] ?? null;

        return $value === null ? '' : User::stamp(Carbon::parse($value, 'UTC'));
    }

    /**
     * What the browser may see of a document. With $full the versions and their files are included.
     *
     * @return array<string, mixed>
     */
    public function view(bool $full = false): array
    {
        $current = $this->currentVersion;

        $view = [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'type' => (string) $this->doc_type,
            'department' => (string) $this->department?->name,
            'departmentId' => $this->department_id,
            'issuedOn' => $this->issued_on?->format('Y-m-d') ?? '',
            'expiresOn' => $this->expires_on?->format('Y-m-d') ?? '',
            'location' => (string) $this->location_note,
            'rev' => $current?->rev_no,
            'fileCount' => $current?->files->count() ?? 0,
            'createdBy' => $this->creator?->name ?? '',
            'createdAt' => User::stamp($this->created_at),
            'updatedAt' => User::stamp($this->updated_at),
            'lastViewedAt' => $this->access('last_viewed_at'),
            'lastDownloadedAt' => $this->access('last_downloaded_at'),
            'archivedVersions' => (int) ($this->getAttributes()['archived_versions'] ?? 0),
        ];

        if ($full) {
            $view['description'] = (string) $this->description;
            $view['versions'] = $this->versions->map->view()->all();
        }

        return $view;
    }
}
