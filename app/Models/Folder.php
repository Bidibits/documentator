<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A folder: holds documents and other folders. Organises only; a document's department still decides who may use it. */
#[Fillable(['parent_id', 'name', 'color', 'icon', 'created_by', 'updated_by'])]
class Folder extends Model
{
    /** The looks a folder can have: a folder, a two-door cabinet, a filing pedestal, a set of drawers, a ring binder. */
    public const ICONS = ['folder', 'cabinet', 'pedestal', 'drawers', 'binder'];

    /** The deepest a folder may sit (the top level is 1). */
    public const MAX_DEPTH = 12;

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Every folder's full name by id ("Contracts / 2026 / Leases"), from one query.
     *
     * @return array<int, string>
     */
    public static function paths(): array
    {
        $rows = self::query()->get(['id', 'parent_id', 'name'])->keyBy('id');
        $paths = [];
        foreach ($rows as $id => $row) {
            $parts = [];
            for ($at = $row, $guard = 0; $at !== null && $guard <= self::MAX_DEPTH + 1; $at = $rows->get($at->parent_id), $guard++) {
                array_unshift($parts, $at->name);
            }
            $paths[(int) $id] = implode(' / ', $parts);
        }

        return $paths;
    }

    /** The folder and every folder inside it, however deep. @return list<int> */
    public static function withDescendants(int $id): array
    {
        $children = self::query()->get(['id', 'parent_id'])->groupBy('parent_id');
        $found = [$id];
        for ($i = 0; $i < count($found); $i++) {
            foreach ($children->get($found[$i], []) as $child) {
                $found[] = (int) $child->id;
            }
        }

        return $found;
    }

    /** How many levels deep the folder sits (a top-level folder is 1; null, the top, is 0). */
    public static function depth(?int $id): int
    {
        $parents = self::query()->pluck('parent_id', 'id');
        $depth = 0;
        for ($at = $id; $at !== null && $depth <= self::MAX_DEPTH + 1; $at = $parents->get($at)) {
            $depth++;
        }

        return $depth;
    }
}
