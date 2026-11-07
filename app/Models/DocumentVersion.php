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
            'files' => $this->files->map->view()->all(),
        ];
    }
}
