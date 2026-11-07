<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code', 'title', 'doc_type', 'department', 'description', 'issued_on', 'expires_on', 'location_note',
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

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('rev_no');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
            'department' => (string) $this->department,
            'issuedOn' => $this->issued_on?->format('Y-m-d') ?? '',
            'expiresOn' => $this->expires_on?->format('Y-m-d') ?? '',
            'location' => (string) $this->location_note,
            'rev' => $current?->rev_no,
            'fileCount' => $current?->files->count() ?? 0,
            'createdBy' => $this->creator?->name ?? '',
            'createdAt' => User::stamp($this->created_at),
            'updatedAt' => User::stamp($this->updated_at),
        ];

        if ($full) {
            $view['description'] = (string) $this->description;
            $view['versions'] = $this->versions->map->view()->all();
        }

        return $view;
    }
}
