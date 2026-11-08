<?php

namespace App\Auth;

use App\Models\User;

/**
 * What a person may do. Permissions are namespaced names ("documentator.document.view"), the way OneHub will name them,
 * and a role is only a bundle of them (config/documentator.php). Every route that needs one is guarded by
 * RequirePermission, so the screen hiding a button is a courtesy and the server is the rule.
 */
final class Access
{
    /** @return array<string, array{label: string, description: string, permissions: list<string>}> */
    public static function roles(): array
    {
        return config('documentator.roles');
    }

    public static function exists(string $role): bool
    {
        return isset(self::roles()[$role]);
    }

    public static function label(string $role): string
    {
        return self::roles()[$role]['label'] ?? $role;
    }

    /** @return list<string> */
    public static function permissions(string $role): array
    {
        return self::roles()[$role]['permissions'] ?? [];
    }

    public static function can(?User $user, string $permission): bool
    {
        return $user !== null && in_array($permission, self::permissions((string) $user->role), true);
    }
}
