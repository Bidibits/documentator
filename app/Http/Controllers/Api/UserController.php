<?php

namespace App\Http\Controllers\Api;

use App\Auth\Access;
use App\Auth\PasswordPolicy;
use App\Auth\SessionUser;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\ChangeLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The Users screen's API (right documentator.user.manage). An administrator makes the accounts and sets every password. */
class UserController extends Controller
{
    private const LOGGED = ['username', 'name', 'role', 'password', 'active', 'auth_source', 'external_id', 'must_change_password', 'password_changed_at'];

    public function index(): JsonResponse
    {
        return response()->json([
            'users' => User::query()->orderBy('name')->get()->map->view()->all(),
            'roles' => collect(Access::roles())->map(fn (array $role) => ['key' => $role['key'], 'label' => $role['label'], 'description' => $role['description']])->values()->all(),
        ]);
    }

    /** Makes an account with a first password the administrator typed; the person must change it at first sign-in. */
    public function store(Request $request): JsonResponse
    {
        $request->merge(['username' => Str::lower(trim((string) $request->input('username')))]);
        $data = $request->validate([
            'username' => ['required', 'string', 'regex:'.User::USERNAME_PATTERN],
            'name' => ['required', 'string', 'max:100'],
            'role' => ['required', 'string'],
            'password' => ['required', 'string', 'max:256'],
        ]);
        $this->checkRole($data['role']);
        $this->checkPassword($data['password'], $data['username']);
        if (User::findByUsername($data['username']) !== null) {
            throw new ApiError(409, 'username_taken');
        }

        $actor = SessionUser::resolve($request);
        try {
            $user = DB::transaction(function () use ($data, $actor) {
                $user = new User(['username' => $data['username'], 'name' => trim($data['name'])]);
                $user->forceFill(['role' => $data['role'], 'password' => $data['password'], 'must_change_password' => true, 'password_changed_at' => now()->utc()])->save();
                ChangeLog::created('users', $user->id, $this->row($user), $actor);
                AuditLog::record($actor, 'user.created', 'role '.Access::label($data['role']), entityType: 'user', entityId: (string) $user->id);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiError(409, 'username_taken');   // made by someone else at the same moment
        }

        return response()->json(['user' => $user->fresh()->view()], 201);
    }

    /** Name, role and whether the account is switched on. Not the password (that is setPassword). */
    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'role' => ['sometimes', 'required', 'string'],
            'active' => ['sometimes', 'required', 'boolean'],
        ]);
        $actor = SessionUser::resolve($request);

        if (isset($data['role'])) {
            $this->checkRole($data['role']);
        }
        $newRole = $data['role'] ?? $user->role;
        $newActive = array_key_exists('active', $data) ? (bool) $data['active'] : (bool) $user->active;

        if ($user->is($actor) && ($newRole !== $user->role || ! $newActive)) {
            throw new ApiError(409, 'own_account');   // an administrator cannot lock themselves out or demote themselves
        }
        $losesAdmin = $user->role === 'admin' && $user->active && ($newRole !== 'admin' || ! $newActive);
        if ($losesAdmin && ! User::where('role', 'admin')->where('active', true)->where('id', '!=', $user->id)->exists()) {
            throw new ApiError(409, 'last_admin');
        }

        DB::transaction(function () use ($user, $data, $actor, $newActive) {
            $before = $this->row($user);
            $changes = Arr::only($data, ['name', 'role']);
            if (isset($changes['name'])) {
                $changes['name'] = trim($changes['name']);
            }
            if (array_key_exists('active', $data)) {
                $changes['active'] = $newActive;
            }
            $user->forceFill($changes)->save();
            $after = $this->row($user->fresh());
            ChangeLog::updated('users', $user->id, $before, $after, $actor);
            if ($newActive !== (bool) $before['active']) {
                AuditLog::record($actor, $newActive ? 'user.switched-on' : 'user.switched-off', entityType: 'user', entityId: (string) $user->id);
            }
        });
        if (! $newActive) {
            SessionUser::endAllFor($user);
        }

        return response()->json(['user' => $user->fresh()->view()]);
    }

    /** Sets a person's password; they must change it at their next sign-in, and are signed out everywhere now. */
    public function setPassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:256']]);
        $actor = SessionUser::resolve($request);

        if ($user->is($actor)) {
            throw new ApiError(409, 'own_account');
        }
        if ($user->auth_source !== User::AUTH_LOCAL) {
            throw new ApiError(409, 'not_local');   // a OneHub account has no password here
        }
        $this->checkPassword($data['password'], $user->username);

        DB::transaction(function () use ($user, $data, $actor) {
            $before = $this->row($user);
            $user->forceFill(['password' => $data['password'], 'must_change_password' => true, 'password_changed_at' => now()->utc()])->save();
            ChangeLog::updated('users', $user->id, $before, $this->row($user->fresh()), $actor);
            AuditLog::record($actor, 'user.password-set', 'by an administrator', entityType: 'user', entityId: (string) $user->id);
        });
        SessionUser::endAllFor($user);

        return response()->json(['user' => $user->fresh()->view()]);
    }

    private function checkRole(string $role): void
    {
        if (! Access::exists($role)) {
            throw new ApiError(422, 'invalid', ['field' => 'role', 'message' => 'unknown']);
        }
    }

    private function checkPassword(string $password, string $username): void
    {
        if (($problem = PasswordPolicy::problem($password, $username)) !== null) {
            throw new ApiError(422, 'invalid', ['field' => 'password', 'message' => $problem]);
        }
    }

    /** The columns that are logged, as the database holds them. */
    private function row(User $user): array
    {
        return Arr::only((array) DB::table('users')->where('id', $user->id)->first(), self::LOGGED);
    }
}
