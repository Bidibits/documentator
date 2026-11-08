<?php

namespace App\Http\Controllers\Api;

use App\Auth\SessionUser;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Support\AuditLog;
use App\Support\ChangeLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Administration > Departments (right documentator.department.manage). */
class DepartmentController extends Controller
{
    private const LOGGED = ['name', 'active'];

    public function index(): JsonResponse
    {
        return response()->json(['departments' => Department::query()->withCount('documents')->orderBy('name')->get()->map->view()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $name = $this->clean($data['name']);
        $this->mustBeFree($name);
        $user = SessionUser::resolve($request);

        $department = DB::transaction(function () use ($name, $user) {
            $department = Department::create(['name' => $name, 'active' => true, 'created_by' => $user->id, 'updated_by' => $user->id]);
            ChangeLog::created('departments', $department->id, ['name' => $name, 'active' => true, 'created_by' => $user->id], $user);
            AuditLog::record($user, 'department.created', $name, entityType: 'department', entityId: (string) $department->id);

            return $department;
        });

        return response()->json(['department' => $department->loadCount('documents')->view()], 201);
    }

    /** Renames it, or switches it off (no longer offered for new documents) or on. Its documents stay with it. */
    public function update(Request $request, Department $department): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'active' => ['sometimes', 'required', 'boolean'],
        ]);
        $user = SessionUser::resolve($request);
        $changes = [];
        if (isset($data['name'])) {
            $changes['name'] = $this->clean($data['name']);
            $this->mustBeFree($changes['name'], $department->id);
        }
        if (array_key_exists('active', $data)) {
            $changes['active'] = (bool) $data['active'];
        }

        DB::transaction(function () use ($department, $changes, $user) {
            $before = Arr::only($department->getAttributes(), self::LOGGED);
            $department->forceFill($changes + ['updated_by' => $user->id])->save();
            $after = Arr::only((array) DB::table('departments')->where('id', $department->id)->first(), self::LOGGED);
            ChangeLog::updated('departments', $department->id, $before, $after, $user);
            if (array_key_exists('active', $changes) && $changes['active'] !== (bool) $before['active']) {
                AuditLog::record($user, $changes['active'] ? 'department.switched-on' : 'department.switched-off', $department->name, entityType: 'department', entityId: (string) $department->id);
            }
        });

        return response()->json(['department' => $department->fresh()->loadCount('documents')->view()]);
    }

    private function clean(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name)) ?? '');
    }

    /** Another department may not have the same name, whatever its case. */
    private function mustBeFree(string $name, ?int $except = null): void
    {
        if ($name === '') {
            throw new ApiError(422, 'invalid', ['field' => 'name', 'message' => 'required']);
        }
        if (Department::query()->where('name', $name)->when($except, fn ($q) => $q->where('id', '!=', $except))->exists()) {
            throw new ApiError(409, 'department_taken');
        }
    }
}
