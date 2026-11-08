<?php

namespace App\Http\Controllers\Api;

use App\Auth\PasswordPolicy;
use App\Auth\SessionUser;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\ChangeLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PasswordController extends Controller
{
    private const LOGGED = ['password', 'password_changed_at', 'must_change_password'];

    /**
     * The one time a person sets their own password: right after an administrator made the account or set its password
     * (nobody else changes their own; an administrator sets every other one). Needs the password they just signed in
     * with, and a different one that passes the policy.
     */
    public function change(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = SessionUser::resolve($request);
        if (! $user->must_change_password) {
            throw ApiError::forbidden();
        }

        $data = $request->validate([
            'current' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'max:256'],
        ]);
        if (! Hash::check($data['current'], $user->password)) {
            throw new ApiError(422, 'invalid', ['field' => 'current', 'message' => 'wrong']);
        }
        if ($data['password'] === $data['current']) {
            throw new ApiError(422, 'invalid', ['field' => 'password', 'message' => 'password_same']);
        }
        if (($problem = PasswordPolicy::problem($data['password'], $user->username)) !== null) {
            throw new ApiError(422, 'invalid', ['field' => 'password', 'message' => $problem]);
        }

        DB::transaction(function () use ($user, $data) {
            $before = Arr::only($user->getAttributes(), self::LOGGED);
            $user->forceFill(['password' => $data['password'], 'password_changed_at' => now()->utc(), 'must_change_password' => false])->save();
            $after = Arr::only((array) DB::table('users')->where('id', $user->id)->first(), self::LOGGED);
            ChangeLog::updated('users', $user->id, $before, $after, $user);
            AuditLog::record($user, 'user.password-changed', 'chosen at first sign-in', entityType: 'user', entityId: (string) $user->id);
        });
        SessionUser::endAllFor($user, $request->session()->getId());

        return response()->json(['user' => $user->fresh()->view()]);
    }
}
