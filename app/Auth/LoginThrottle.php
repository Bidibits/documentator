<?php

namespace App\Auth;

use App\Exceptions\ApiError;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Wrong passwords lock the username (5 tries) and the visitor's address (25 tries) for 15 minutes.
 */
final class LoginThrottle
{
    private string $userKey;

    private string $ipKey;

    public function __construct(string $username, string $ip)
    {
        $this->userKey = 'login:user:'.sha1(Str::lower($username));
        $this->ipKey = 'login:ip:'.$ip;
    }

    public function ensureNotLocked(): void
    {
        $seconds = max(
            $this->lockedFor($this->userKey, config('documentator.login.user_attempts')),
            $this->lockedFor($this->ipKey, config('documentator.login.ip_attempts')),
        );

        if ($seconds > 0) {
            throw new ApiError(429, 'locked', ['minutes' => (int) ceil($seconds / 60)]);
        }
    }

    public function failed(): void
    {
        $decay = config('documentator.login.lock_minutes') * 60;
        RateLimiter::hit($this->userKey, $decay);
        RateLimiter::hit($this->ipKey, $decay);
    }

    public function succeeded(): void
    {
        RateLimiter::clear($this->userKey);
    }

    private function lockedFor(string $key, int $attempts): int
    {
        return RateLimiter::tooManyAttempts($key, $attempts) ? max(1, RateLimiter::availableIn($key)) : 0;
    }
}
