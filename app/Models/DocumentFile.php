<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['version_id', 'original_name', 'storage_path', 'mime', 'size', 'sha256', 'sort_order'])]
class DocumentFile extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['last_viewed_at' => 'datetime', 'last_downloaded_at' => 'datetime'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_id');
    }

    public function viewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_viewed_by');
    }

    public function downloader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_downloaded_by');
    }

    /**
     * What the browser may see of a file: never where it is stored.
     *
     * @return array<string, mixed>
     */
    public function view(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'mime' => $this->mime,
            'size' => (int) $this->size,
            'sha256' => $this->sha256,
            'lastViewedAt' => User::stamp($this->last_viewed_at),
            'lastViewedBy' => $this->viewer?->name ?? '',
            'lastDownloadedAt' => User::stamp($this->last_downloaded_at),
            'lastDownloadedBy' => $this->downloader?->name ?? '',
        ];
    }
}
