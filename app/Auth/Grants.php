<?php

namespace App\Auth;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * What one person may do, in which departments: for each permission, the departments it is granted in and whether it
 * is granted for documents with no department. An administrator may do everything everywhere.
 */
final class Grants
{
    /** @param array<string, array{ids: list<int>, none: bool}> $byPermission */
    public function __construct(public readonly bool $admin, private readonly array $byPermission = []) {}

    /** May the person use this permission on a document of this department (null: a document with no department)? */
    public function can(string $permission, ?int $departmentId): bool
    {
        if ($this->admin) {
            return true;
        }

        return $departmentId === null ? $this->includesNone($permission) : in_array($departmentId, $this->departmentIds($permission), true);
    }

    /** Is the permission granted in at least one department (or for documents with none)? */
    public function anywhere(string $permission): bool
    {
        return $this->admin || $this->departmentIds($permission) !== [] || $this->includesNone($permission);
    }

    /** @return list<int> */
    public function departmentIds(string $permission): array
    {
        return $this->byPermission[$permission]['ids'] ?? [];
    }

    public function includesNone(string $permission): bool
    {
        return $this->byPermission[$permission]['none'] ?? false;
    }

    /** Narrows a query to the rows whose department_id column is one this person may use the permission on. */
    public function scope(QueryBuilder|EloquentBuilder $query, string $column, string $permission): void
    {
        if ($this->admin) {
            return;
        }
        $ids = $this->departmentIds($permission);
        $none = $this->includesNone($permission);
        $query->where(function ($where) use ($column, $ids, $none) {
            $where->whereIn($column, $ids);
            if ($none) {
                $where->orWhereNull($column);
            }
        });
    }
}
