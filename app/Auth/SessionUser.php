<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Who is signed in on this request. Idle sessions end after SESSION_LIFETIME; this adds the absolute limit and
 * ends the session as soon as the account is switched off.
 */
final class SessionUser
{
    private const SIGNED_IN_AT = 'documentator.signed_in_at';

    public static function resolve(Request $request): ?User
    {
        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            return null;
        }

        $signedInAt = (int) $request->session()->get(self::SIGNED_IN_AT, 0);
        $tooOld = $signedInAt < now()->subDays(config('documentator.session_max_days'))->getTimestamp();

        if (! $user->active || $tooOld) {
            self::end($request);

            return null;
        }

        return $user;
    }

    public static function start(Request $request, User $user): void
    {
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(self::SIGNED_IN_AT, now()->getTimestamp());
    }

    public static function end(Request $request): void
    {
        Auth::logout();
        $request->session()->invalidate();
    }

    /**
     * Signs a person out everywhere, except (optionally) the session making this request.
     */
    public static function endAllFor(User $user, ?string $keepSessionId = null): void
    {
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->when($keepSessionId, fn ($query) => $query->where('id', '!=', $keepSessionId))
            ->delete();
    }
}
