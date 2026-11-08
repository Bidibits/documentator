<?php

namespace App\Http\Controllers\Api;

use App\Auth\Access;
use App\Auth\SessionUser;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Support\AuditLog;
use App\Support\ChangeLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Administration > Roles (right documentator.role.manage). A role is a name and a grid: for each department (and for
 * documents with no department) which of view / download / register / update / archive its people may do. The
 * Administrator role is the one system role: everything everywhere, never edited.
 */
class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = DB::table('roles')->orderBy('id')->get();
        $people = DB::table('users')->selectRaw('role, count(*) as n')->groupBy('role')->pluck('n', 'role');
        $grants = DB::table('role_grants')->get()->groupBy('role_id');
        $shortOf = array_flip(Access::TAGS);

        return response()->json([
            'roles' => $roles->map(fn ($r) => [
                'id' => (int) $r->id,
                'key' => $r->key,
                'name' => $r->name,
                'description' => (string) $r->description,
                'system' => (bool) $r->is_system,
                'people' => (int) ($people[$r->key] ?? 0),
                // department id (0: no department) => the short names of what it may do there
                'grants' => (object) ($grants[$r->id] ?? collect())->groupBy('department_id')
                    ->map(fn ($rows) => $rows->map(fn ($g) => $shortOf[$g->permission] ?? null)->filter()->values()->all())->all(),
            ])->all(),
            'departments' => Department::query()->orderByDesc('active')->orderBy('name')->get(['id', 'name', 'active'])
                ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'active' => (bool) $d->active])->all(),
            'tags' => collect(Access::TAGS)->keys()->map(fn ($key) => ['key' => $key, 'reserved' => $key === 'delete'])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:300'],
        ]);
        $name = $this->clean($data['name']);
        $this->mustBeFree($name);
        $user = SessionUser::resolve($request);

        $id = DB::transaction(function () use ($name, $data, $user) {
            $now = now()->utc();
            $key = $this->keyFor($name);
            $id = DB::table('roles')->insertGetId([
                'key' => $key, 'name' => $name, 'description' => $data['description'] ?? null, 'is_system' => false,
                'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            ChangeLog::created('roles', $id, ['key' => $key, 'name' => $name, 'description' => $data['description'] ?? null], $user);
            AuditLog::record($user, 'role.created', $name, entityType: 'role', entityId: (string) $id);

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    /** The name, the description, and the grid (which replaces the whole grid: departments => short names). */
    public function update(Request $request, int $role): JsonResponse
    {
        $row = DB::table('roles')->where('id', $role)->first() ?? throw new ApiError(404, 'route');
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'grants' => ['sometimes', 'array'],
        ]);
        if ($row->is_system) {
            throw new ApiError(409, 'system_role');
        }
        $user = SessionUser::resolve($request);
        $name = isset($data['name']) ? $this->clean($data['name']) : null;
        if ($name !== null) {
            $this->mustBeFree($name, $role);
        }
        $grid = isset($data['grants']) ? $this->normalise($data['grants']) : null;

        DB::transaction(function () use ($row, $data, $name, $grid, $user) {
            $before = ['name' => $row->name, 'description' => $row->description];
            $after = $before;
            if ($name !== null) {
                $after['name'] = $name;
            }
            if (array_key_exists('description', $data)) {
                $after['description'] = $data['description'];
            }
            if ($grid !== null) {
                $before['grants'] = $this->text($row->id);
                DB::table('role_grants')->where('role_id', $row->id)->delete();
                foreach ($grid as $department => $permissions) {
                    foreach ($permissions as $permission) {
                        DB::table('role_grants')->insert(['role_id' => $row->id, 'department_id' => $department, 'permission' => $permission]);
                    }
                }
                $after['grants'] = $this->text($row->id);
            }
            DB::table('roles')->where('id', $row->id)->update(['name' => $after['name'], 'description' => $after['description'], 'updated_by' => $user->id, 'updated_at' => now()->utc()]);
            ChangeLog::updated('roles', $row->id, $before, $after, $user);
            AuditLog::record($user, 'role.updated', $after['name'], entityType: 'role', entityId: (string) $row->id);
        });

        return response()->json(['ok' => true]);
    }

    /** Only a role nobody has, and never the Administrator. */
    public function destroy(Request $request, int $role): JsonResponse
    {
        $row = DB::table('roles')->where('id', $role)->first() ?? throw new ApiError(404, 'route');
        if ($row->is_system) {
            throw new ApiError(409, 'system_role');
        }
        $people = DB::table('users')->where('role', $row->key)->count();
        if ($people > 0) {
            throw new ApiError(409, 'role_in_use', ['people' => $people]);
        }
        $user = SessionUser::resolve($request);

        DB::transaction(function () use ($row, $user) {
            $values = ['key' => $row->key, 'name' => $row->name, 'description' => $row->description, 'grants' => $this->text($row->id)];
            DB::table('role_grants')->where('role_id', $row->id)->delete();
            DB::table('roles')->where('id', $row->id)->delete();
            ChangeLog::deleted('roles', $row->id, $values, $user);
            AuditLog::record($user, 'role.deleted', $row->name, entityType: 'role', entityId: (string) $row->id);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * The grid as sent ({"3": ["view", "download"], "0": ["view"]}) checked and turned into department => permissions.
     * A department that exists (or 0), only the known short names, and "view" is always there when anything else is:
     * you cannot download what you cannot see.
     *
     * @param  array<array-key, mixed>  $grid
     * @return array<int, list<string>>
     */
    private function normalise(array $grid): array
    {
        $known = Department::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $out = [];
        foreach ($grid as $department => $tags) {
            $department = (int) $department;
            if (! is_array($tags) || ($department !== Access::NO_DEPARTMENT && ! in_array($department, $known, true))) {
                throw new ApiError(422, 'invalid', ['field' => 'grants', 'message' => 'department']);
            }
            $permissions = [];
            foreach ($tags as $tag) {
                if (! is_string($tag) || ! isset(Access::TAGS[$tag])) {
                    throw new ApiError(422, 'invalid', ['field' => 'grants', 'message' => 'tag']);
                }
                $permissions[Access::TAGS[$tag]] = true;
            }
            if ($permissions !== []) {
                $permissions[Access::TAGS['view']] = true;
                // in the order of the grid's columns
                $out[$department] = array_values(array_filter(Access::TAGS, fn ($permission) => isset($permissions[$permission])));
            }
        }

        return $out;
    }

    /** The grid as one value, for the audit log: "HR: view, download; No department: view" (KVNsys logs rights this way). */
    private function text(int $roleId): ?string
    {
        $short = array_flip(Access::TAGS);
        $names = Department::query()->pluck('name', 'id')->all();
        $byDepartment = DB::table('role_grants')->where('role_id', $roleId)->get()->groupBy('department_id');

        $parts = $byDepartment->map(function ($rows, $department) use ($short, $names) {
            $tags = collect(Access::TAGS)->keys()->filter(fn ($tag) => $rows->contains(fn ($g) => ($short[$g->permission] ?? null) === $tag))->implode(', ');

            return [(int) $department === Access::NO_DEPARTMENT ? 'No department' : ($names[$department] ?? 'Department '.$department), $tags];
        })->sortBy(fn ($pair) => [$pair[0] === 'No department' ? 1 : 0, mb_strtolower($pair[0])])->map(fn ($pair) => $pair[0].': '.$pair[1])->values();

        return $parts->isEmpty() ? null : $parts->implode('; ');
    }

    private function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text)) ?? '');
    }

    private function mustBeFree(string $name, ?int $except = null): void
    {
        if ($name === '') {
            throw new ApiError(422, 'invalid', ['field' => 'name', 'message' => 'required']);
        }
        $taken = DB::table('roles')->pluck('name', 'id')->contains(fn ($other, $id) => mb_strtolower($other) === mb_strtolower($name) && $id !== $except);
        if ($taken) {
            throw new ApiError(409, 'role_taken');
        }
    }

    /** A short stable key for the name ("HR clerk" -> "hr-clerk"), made unique. */
    private function keyFor(string $name): string
    {
        $base = Str::limit(Str::slug($name), 30, '') ?: 'role';
        $key = $base;
        for ($i = 2; DB::table('roles')->where('key', $key)->exists(); $i++) {
            $key = $base.'-'.$i;
        }

        return $key;
    }
}
