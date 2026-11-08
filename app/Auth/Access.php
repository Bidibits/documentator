<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What a person may do. Permissions are namespaced names ("documentator.document.view"), the way OneHub will name them.
 *
 *  - Document tags are granted per department through the person's role (Administration > Roles): view, download,
 *    register, update, archive, delete. {@see Grants} answers "in this department?".
 *  - Global permissions (users, departments, roles, audits, archive rules) belong to the Administrator role alone.
 *
 * Every route that needs one is guarded by RequirePermission and the controllers check the department, so the screen
 * hiding a button is a courtesy and the server is the rule.
 */
final class Access
{
    /** Short name => permission. The checkbox columns of the role grid, in order. */
    public const TAGS = [
        'view' => 'documentator.document.view',
        'download' => 'documentator.file.download',
        'register' => 'documentator.document.create',
        'update' => 'documentator.version.create',
        'archive' => 'documentator.version.archive',
        'delete' => 'documentator.document.delete',   // delete a document for good
    ];

    public const GLOBAL = [
        'documentator.archive.manage',   // the archive rules, and running them
        'documentator.audit.view',
        'documentator.user.manage',
        'documentator.department.manage',
        'documentator.role.manage',
    ];

    public const ADMIN = 'admin';

    /** The department_id the grid and role_grants use for documents with no department. */
    public const NO_DEPARTMENT = 0;

    /** @return array<string, array{id: int, key: string, label: string, description: string, system: bool}> by key */
    public static function roles(): array
    {
        return DB::table('roles')->orderBy('id')->get()->mapWithKeys(fn ($r) => [$r->key => [
            'id' => (int) $r->id, 'key' => $r->key, 'label' => $r->name, 'description' => (string) $r->description, 'system' => (bool) $r->is_system,
        ]])->all();
    }

    public static function exists(string $role): bool
    {
        return DB::table('roles')->where('key', $role)->exists();
    }

    public static function label(string $role): string
    {
        return (string) (DB::table('roles')->where('key', $role)->value('name') ?? $role);
    }

    /** The person's rights per department, read now. */
    public static function grants(?User $user): Grants
    {
        if ($user === null) {
            return new Grants(false);
        }
        if ($user->role === self::ADMIN) {
            return new Grants(true);
        }

        $by = [];
        $rows = DB::table('role_grants as g')->join('roles as r', 'r.id', '=', 'g.role_id')->where('r.key', $user->role)->get(['g.department_id', 'g.permission']);
        foreach ($rows as $row) {
            $by[$row->permission] ??= ['ids' => [], 'none' => false];
            if ((int) $row->department_id === self::NO_DEPARTMENT) {
                $by[$row->permission]['none'] = true;
            } else {
                $by[$row->permission]['ids'][] = (int) $row->department_id;
            }
        }

        return new Grants(false, $by);
    }

    /**
     * May the person use this permission? For a document tag that means in at least one department; the controller
     * then checks the document's own department. A global permission is the Administrator's alone.
     */
    public static function can(?User $user, string $permission): bool
    {
        if ($user === null) {
            return false;
        }
        if (in_array($permission, self::TAGS, true)) {
            return self::grants($user)->anywhere($permission);
        }

        return $user->role === self::ADMIN && in_array($permission, self::GLOBAL, true);
    }

    /** @return list<string> every permission the person holds somewhere (what the screen uses to show or hide) */
    public static function permissions(User $user): array
    {
        $grants = self::grants($user);
        $admin = $user->role === self::ADMIN;

        return array_values(array_filter(
            [...array_values(self::TAGS), ...self::GLOBAL],
            fn (string $permission) => in_array($permission, self::TAGS, true) ? $grants->anywhere($permission) : $admin,
        ));
    }
}
