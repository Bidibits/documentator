<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

#[Fillable([
    'username', 'name', 'password', 'active', 'auth_source', 'external_id',
    'last_login_at', 'password_changed_at',
])]
#[Hidden(['password'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    public const USERNAME_PATTERN = '/^[a-z0-9._-]{3,32}$/';

    /** Where an account's identity comes from: this app now, OneHub later. */
    public const AUTH_LOCAL = 'local';

    public const AUTH_ONEHUB = 'onehub';

    // no "remember me": the session ends with the idle and absolute limits
    protected $rememberTokenName = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public static function findByUsername(string $username): ?self
    {
        return static::where('username', Str::lower(trim($username)))->first();
    }

    /**
     * What the browser may see of an account.
     *
     * @return array<string, mixed>
     */
    public function view(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->name,
            'active' => $this->active,
            'authSource' => $this->auth_source,
            'lastLogin' => self::stamp($this->last_login_at),
        ];
    }

    /**
     * Timestamps as the browser reads them: 2026-10-12T05:34:00.962Z.
     */
    public static function stamp(?CarbonInterface $at): string
    {
        return $at ? $at->copy()->utc()->format('Y-m-d\TH:i:s.v\Z') : '';
    }
}
