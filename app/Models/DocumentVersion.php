<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['document_id', 'rev_no', 'change_note', 'created_by'])]
class DocumentVersion extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'restored_at' => 'datetime'];
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(DocumentFile::class, 'version_id')->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function view(): array
    {
        return [
            'id' => $this->id,
            'rev' => $this->rev_no,
            'changeNote' => (string) $this->change_note,
            'createdBy' => $this->creator?->name ?? '',
            'createdAt' => User::stamp($this->created_at),
            'archived' => $this->isArchived(),
            'archivedAt' => User::stamp($this->archived_at),
            'archivedBy' => $this->archiver?->name ?? ($this->isArchived() ? 'an archive rule' : ''),
            'archiveSize' => (int) $this->archive_size,
            'files' => $this->files->map->view()->all(),
        ];
    }
}
