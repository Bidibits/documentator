<?php

namespace App\Models;

use App\Auth\Access;
use App\Auth\Grants;
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
    'code', 'title', 'doc_type', 'department_id', 'folder_id', 'remind_days', 'description', 'issued_on', 'expires_on', 'location_note',
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

    /**
     * Where the document stands against its expiry date, by the office's calendar: none (no expiry date), active,
     * expiring (within the warning days, today included) or expired (the date has passed). days is whole days left,
     * negative once expired, null without a date.
     *
     * @return array{status: string, days: ?int}
     */
    public function expiry(): array
    {
        $lead = $this->leadDays();
        if ($this->expires_on === null) {
            return ['status' => 'none', 'days' => null];
        }
        $today = Carbon::today(config('documentator.timezone'))->startOfDay();
        $days = (int) $today->diffInDays(Carbon::parse($this->expires_on->format('Y-m-d'), config('documentator.timezone'))->startOfDay(), false);

        return ['status' => $days < 0 ? 'expired' : ($days <= $lead ? 'expiring' : 'active'), 'days' => $days];
    }

    /** How many days before the expiry date this document starts to be flagged: its own setting, else the default. */
    public function leadDays(): int
    {
        return $this->remind_days !== null ? (int) $this->remind_days : (int) config('documentator.expiry_warning_days');
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
    public function view(bool $full = false, ?Grants $grants = null, ?array $folderPaths = null): array
    {
        $current = $this->currentVersion;
        $expiry = $this->expiry();

        $view = [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'type' => (string) $this->doc_type,
            'department' => (string) $this->department?->name,
            'departmentId' => $this->department_id,
            'folderId' => $this->folder_id,
            'folder' => $this->folder_id === null ? '' : (string) (($folderPaths ?? Folder::paths())[$this->folder_id] ?? ''),
            'issuedOn' => $this->issued_on?->format('Y-m-d') ?? '',
            'expiresOn' => $this->expires_on?->format('Y-m-d') ?? '',
            'remindDays' => $this->remind_days,
            'leadDays' => $this->leadDays(),
            'expiryStatus' => $expiry['status'],
            'daysLeft' => $expiry['days'],
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

        // what the signed-in person may do with this document, for the screen to show or hide buttons
        if ($grants !== null) {
            $view['can'] = [
                'download' => $grants->can(Access::TAGS['download'], $this->department_id),
                'update' => $grants->can(Access::TAGS['update'], $this->department_id),
                'archive' => $grants->can(Access::TAGS['archive'], $this->department_id),
                'delete' => $grants->can(Access::TAGS['delete'], $this->department_id),
            ];
        }

        if ($full) {
            $view['description'] = (string) $this->description;
            $view['versions'] = $this->versions->map->view()->all();
        }

        return $view;
    }
}
