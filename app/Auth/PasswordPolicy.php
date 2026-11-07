<?php

namespace App\Auth;

use Illuminate\Support\Str;

final class PasswordPolicy
{
    /**
     * The error code for a password that should not be accepted, or null when the password is fine.
     */
    public static function problem(string $password, string $username): ?string
    {
        return match (true) {
            mb_strlen($password) < 8 => 'password_short',
            mb_strlen($password) > 128 => 'password_long',
            Str::lower($password) === Str::lower($username) => 'password_username',
            default => null,
        };
    }
}
