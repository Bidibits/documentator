<?php

namespace App\Http\Controllers\Api;

use App\Auth\LoginThrottle;
use App\Auth\SessionUser;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Http\Support\JsonBody;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class SessionController extends Controller
{
    /**
     * Who is signed in here, or nobody: the app asks this before it shows the sign-in screen.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['user' => SessionUser::resolve($request)?->view()]);
    }

    public function login(Request $request): JsonResponse
    {
        $body = JsonBody::object($request, 8192);
        $username = trim(JsonBody::string($body, 'username'));
        $password = JsonBody::string($body, 'password');

        $throttle = new LoginThrottle($username, (string) $request->ip());
        $throttle->ensureNotLocked();

        $user = $username === '' ? null : User::findByUsername($username);
        if (! $this->passwordMatches($user, $password)) {
            $throttle->failed();
            Log::warning('Failed sign-in', ['username' => mb_substr($username, 0, 64), 'ip' => $request->ip()]);
            AuditLog::record($user, 'sign-in.failed', username: mb_substr($username, 0, 64));
            throw new ApiError(401, 'bad_login');
        }
        $throttle->succeeded();

        if (! $user->active) {
            AuditLog::record($user, 'sign-in.refused', 'account switched off');
            throw new ApiError(403, 'inactive');
        }

        SessionUser::start($request, $user);
        $user->forceFill(['last_login_at' => now()])->save();
        AuditLog::record($user, 'sign-in');

        return response()->json(['user' => $user->view()]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = SessionUser::resolve($request);
        SessionUser::end($request);
        if ($user !== null) {
            AuditLog::record($user, 'sign-out');
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @phpstan-assert-if-true User $user
     */
    private function passwordMatches(?User $user, string $password): bool
    {
        if ($user === null || $password === '') {
            // as slow as a real check, so the answer time does not tell which usernames exist
            Hash::make($password);

            return false;
        }

        if ($user->auth_source !== User::AUTH_LOCAL || ! Hash::check($password, $user->password)) {
            return false;
        }
        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->save();
        }

        return true;
    }
}
