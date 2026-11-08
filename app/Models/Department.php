<?php

namespace App\Models;

use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A department documents belong to. Switched off, never deleted: its documents keep pointing at it. */
#[Fillable(['name', 'active', 'created_by', 'updated_by'])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** @return array<string, mixed> */
    public function view(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'active' => $this->active,
            'documents' => (int) ($this->documents_count ?? $this->documents()->count()),
            'createdAt' => User::stamp($this->created_at),
        ];
    }
}
