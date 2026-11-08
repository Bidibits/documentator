<?php

namespace App\Http\Controllers\Api;

use App\Auth\Access;
use App\Auth\SessionUser;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Folder;
use App\Support\AuditLog;
use App\Support\ChangeLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Folders, like a drive. Anyone who may view documents sees the folders (their names and how many documents each holds
 * that the person may see); anyone who may register documents may make, rename, move and remove them. A folder is only
 * removed when it is empty.
 */
class FolderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $grants = Access::grants(SessionUser::resolve($request));
        $counts = Document::query();
        $grants->scope($counts, 'documents.department_id', Access::TAGS['view']);
        $counts = $counts->selectRaw('folder_id, count(*) as n')->groupBy('folder_id')->pluck('n', 'folder_id');
        $paths = Folder::paths();
        $children = Folder::query()->selectRaw('parent_id, count(*) as n')->groupBy('parent_id')->pluck('n', 'parent_id');

        return response()->json([
            'folders' => Folder::query()->orderBy('name')->get()->map(fn (Folder $f) => [
                'id' => $f->id, 'parentId' => $f->parent_id, 'name' => $f->name, 'color' => $f->color, 'path' => $paths[$f->id] ?? $f->name,
                'documents' => (int) ($counts[$f->id] ?? 0), 'folders' => (int) ($children[$f->id] ?? 0),
            ])->all(),
            'rootDocuments' => (int) ($counts[''] ?? 0),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer', 'exists:folders,id'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);
        $name = $this->clean($data['name']);
        $parent = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        $color = isset($data['color']) ? strtoupper($data['color']) : null;
        $this->mustFit($name, $parent);
        if (Folder::depth($parent) >= Folder::MAX_DEPTH) {
            throw new ApiError(422, 'invalid', ['field' => 'parent_id', 'message' => 'depth']);
        }
        $user = SessionUser::resolve($request);

        $folder = DB::transaction(function () use ($name, $parent, $color, $user) {
            $folder = Folder::create(['parent_id' => $parent, 'name' => $name, 'color' => $color, 'created_by' => $user->id, 'updated_by' => $user->id]);
            ChangeLog::created('folders', $folder->id, ['parent_id' => $parent, 'name' => $name, 'color' => $color, 'created_by' => $user->id], $user);
            AuditLog::record($user, 'folder.created', $name, entityType: 'folder', entityId: (string) $folder->id);

            return $folder;
        });

        return response()->json(['folder' => ['id' => $folder->id, 'parentId' => $folder->parent_id, 'name' => $folder->name, 'color' => $folder->color]], 201);
    }

    /** Renames it, recolours it, moves it into another folder (or to the top level with parent_id null), or any of those. */
    public function update(Request $request, Folder $folder): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:folders,id'],
            'color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);
        $name = isset($data['name']) ? $this->clean($data['name']) : $folder->name;
        $color = array_key_exists('color', $data) ? (isset($data['color']) ? strtoupper($data['color']) : null) : $folder->color;
        $parent = array_key_exists('parent_id', $data) ? ($data['parent_id'] === null ? null : (int) $data['parent_id']) : $folder->parent_id;

        if ($parent !== null && in_array($parent, Folder::withDescendants($folder->id), true)) {
            throw new ApiError(422, 'invalid', ['field' => 'parent_id', 'message' => 'inside_itself']);   // into itself or something inside it
        }
        $this->mustFit($name, $parent, $folder->id);
        // the folder and what is inside it must still fit under the depth limit
        $height = $this->height($folder->id, Folder::query()->get(['id', 'parent_id'])->groupBy('parent_id'));
        if (Folder::depth($parent) + $height > Folder::MAX_DEPTH) {
            throw new ApiError(422, 'invalid', ['field' => 'parent_id', 'message' => 'depth']);
        }
        $user = SessionUser::resolve($request);

        DB::transaction(function () use ($folder, $name, $parent, $color, $user) {
            $before = ['parent_id' => $folder->parent_id, 'name' => $folder->name, 'color' => $folder->color, 'updated_by' => $folder->updated_by];
            $after = ['parent_id' => $parent, 'name' => $name, 'color' => $color, 'updated_by' => $user->id];
            if ($before['parent_id'] === $parent && $before['name'] === $name && $before['color'] === $color) {
                return;
            }
            $folder->update($after);
            ChangeLog::updated('folders', $folder->id, $before, $after, $user);
            AuditLog::record($user, 'folder.updated', $name, entityType: 'folder', entityId: (string) $folder->id);
        });

        return response()->json(['folder' => ['id' => $folder->id, 'parentId' => $folder->parent_id, 'name' => $folder->name, 'color' => $folder->color]]);
    }

    /** Only an empty folder: no documents, no folders inside. */
    public function destroy(Request $request, Folder $folder): JsonResponse
    {
        if (Document::where('folder_id', $folder->id)->exists() || Folder::where('parent_id', $folder->id)->exists()) {
            throw new ApiError(409, 'folder_not_empty');
        }
        $user = SessionUser::resolve($request);

        DB::transaction(function () use ($folder, $user) {
            ChangeLog::deleted('folders', $folder->id, ['parent_id' => $folder->parent_id, 'name' => $folder->name, 'color' => $folder->color, 'created_by' => $folder->created_by], $user);
            $folder->delete();
            AuditLog::record($user, 'folder.deleted', $folder->name, entityType: 'folder', entityId: (string) $folder->id);
        });

        return response()->json(['ok' => true]);
    }

    /** Levels in the folder's own tree, itself included (a folder with nothing inside is 1). */
    private function height(int $id, Collection $children, int $guard = 0): int
    {
        $deepest = 0;
        if ($guard <= Folder::MAX_DEPTH) {
            foreach ($children->get($id, []) as $child) {
                $deepest = max($deepest, $this->height((int) $child->id, $children, $guard + 1));
            }
        }

        return 1 + $deepest;
    }

    private function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text)) ?? '');
    }

    /** A name is needed, cannot hold a slash (paths are shown with one), and cannot repeat among its neighbours. */
    private function mustFit(string $name, ?int $parent, ?int $except = null): void
    {
        if ($name === '' || str_contains($name, '/')) {
            throw new ApiError(422, 'invalid', ['field' => 'name']);
        }
        $taken = Folder::query()->where('parent_id', $parent)->when($except, fn ($q) => $q->where('id', '!=', $except))->pluck('name')
            ->contains(fn ($other) => mb_strtolower($other) === mb_strtolower($name));
        if ($taken) {
            throw new ApiError(409, 'folder_taken');
        }
    }
}
