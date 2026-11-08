<?php

namespace Tests\Feature;

use App\Auth\Access;
use App\Models\Department;
use App\Models\Document;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** Folders like a drive: nested, documents sit in one, and only empty folders go. */
class FoldersTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private const FORM = ['X-Documentator' => '1', 'Accept' => 'application/json'];

    private Department $hr;

    private Department $acc;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->hr = Department::factory()->create(['name' => 'HR']);
        $this->acc = Department::factory()->create(['name' => 'Accounting']);
        User::factory()->create(['username' => 'ana', 'name' => 'Ana Reyes']);   // an administrator
        $this->as('ana');
    }

    private function as(string $username): void
    {
        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie(config('session.cookie'), '');
        $this->signIn($username)->assertOk();
    }

    private function person(string $username, array $grid): User
    {
        $id = DB::table('roles')->insertGetId(['key' => 'r-'.$username, 'name' => 'R '.$username, 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($grid as $department => $tags) {
            foreach ($tags as $tag) {
                DB::table('role_grants')->insert(['role_id' => $id, 'department_id' => $department, 'permission' => Access::TAGS[$tag]]);
            }
        }

        return User::factory()->create(['username' => $username, 'name' => ucfirst($username), 'role' => 'r-'.$username]);
    }

    private function folder(string $name, ?int $parent = null): int
    {
        return $this->api('POST', '/api/folders', ['name' => $name, 'parent_id' => $parent])->assertCreated()->json('folder.id');
    }

    private function register(array $fields = []): int
    {
        $fields += ['code' => 'TSI-1', 'title' => 'Business permit', 'files' => [UploadedFile::fake()->create('permit.pdf', 10, 'application/pdf')]];

        return $this->post('/api/documents', $fields, self::FORM)->assertCreated()->json('document.id');
    }

    // ---- making them ----

    public function test_folders_nest_as_deep_as_wanted_and_show_their_full_path(): void
    {
        $contracts = $this->folder('Contracts');
        $y2026 = $this->folder('2026', $contracts);
        $leases = $this->folder('Leases', $y2026);

        $list = collect($this->api('GET', '/api/folders')->assertOk()->json('folders'))->keyBy('id');
        $this->assertSame('Contracts / 2026 / Leases', $list[$leases]['path']);
        $this->assertSame($y2026, $list[$leases]['parentId']);
        $this->assertNull($list[$contracts]['parentId']);
        $this->assertSame(1, $list[$contracts]['folders']);
        $this->assertSame(0, $list[$leases]['folders']);
    }

    public function test_a_folder_needs_a_clean_name_that_is_free_among_its_neighbours(): void
    {
        $a = $this->folder('Contracts');
        $this->api('POST', '/api/folders', ['name' => '  '])->assertStatus(422)->assertJsonPath('field', 'name');
        $this->api('POST', '/api/folders', ['name' => 'a/b'])->assertStatus(422)->assertJsonPath('field', 'name');
        $this->api('POST', '/api/folders', ['name' => 'contracts'])->assertStatus(409)->assertExactJson(['error' => 'folder_taken']);
        $this->api('POST', '/api/folders', ['name' => 'X', 'parent_id' => 999])->assertStatus(422);

        // the same name is fine one level down, or in another folder
        $inside = $this->folder('Contracts', $a);
        $this->api('POST', '/api/folders', ['name' => 'CONTRACTS', 'parent_id' => $a])->assertStatus(409);
        $this->assertSame('Contracts / Contracts', collect($this->api('GET', '/api/folders')->json('folders'))->firstWhere('id', $inside)['path']);
        $spaced = $this->folder('  A   B ');
        $this->assertSame('A B', collect($this->api('GET', '/api/folders')->json('folders'))->firstWhere('id', $spaced)['name']);
    }

    public function test_folders_stop_at_twelve_levels(): void
    {
        $parent = null;
        for ($i = 1; $i <= 12; $i++) {
            $parent = $this->folder("L{$i}", $parent);
        }

        $this->api('POST', '/api/folders', ['name' => 'too deep', 'parent_id' => $parent])->assertStatus(422)->assertJsonPath('message', 'depth');
    }

    // ---- renaming and moving ----

    public function test_a_folder_is_renamed_and_moved_with_everything_in_it(): void
    {
        $a = $this->folder('A');
        $b = $this->folder('B');
        $child = $this->folder('Child', $a);
        $doc = $this->register(['code' => 'D-1', 'folder_id' => $child]);

        $this->api('PATCH', "/api/folders/{$a}", ['name' => 'Alpha'])->assertOk()->assertJsonPath('folder.name', 'Alpha');
        $this->api('PATCH', "/api/folders/{$a}", ['parent_id' => $b])->assertOk()->assertJsonPath('folder.parentId', $b);
        $this->assertSame('B / Alpha / Child', $this->api('GET', "/api/documents/{$doc}")->json('document.folder'));

        $this->api('PATCH', "/api/folders/{$a}", ['parent_id' => null])->assertOk()->assertJsonPath('folder.parentId', null);
        $this->assertSame('Alpha / Child', $this->api('GET', "/api/documents/{$doc}")->json('document.folder'));
    }

    public function test_a_folder_cannot_go_into_itself_or_into_what_is_inside_it_or_where_the_name_is_taken(): void
    {
        $a = $this->folder('A');
        $b = $this->folder('B', $a);
        $c = $this->folder('C', $b);
        $other = $this->folder('B');   // a top-level B

        $this->api('PATCH', "/api/folders/{$a}", ['parent_id' => $a])->assertStatus(422)->assertJsonPath('message', 'inside_itself');
        $this->api('PATCH', "/api/folders/{$a}", ['parent_id' => $c])->assertStatus(422)->assertJsonPath('message', 'inside_itself');
        $this->api('PATCH', "/api/folders/{$b}", ['parent_id' => null])->assertStatus(409);   // there is a top-level B already
        $this->api('PATCH', "/api/folders/{$other}", ['name' => 'Z'])->assertOk();
        $this->api('PATCH', "/api/folders/{$b}", ['parent_id' => null])->assertOk();
    }

    public function test_moving_a_folder_must_keep_its_tree_under_the_depth_limit(): void
    {
        $tall = null;
        for ($i = 1; $i <= 11; $i++) {
            $tall = $this->folder("T{$i}", $tall);
        }
        $root = collect($this->api('GET', '/api/folders')->json('folders'))->firstWhere('name', 'T1')['id'];
        $deep = $this->folder('Deep', $this->folder('Level one'));   // two levels down already
        $this->api('PATCH', "/api/folders/{$root}", ['parent_id' => $deep])->assertStatus(422)->assertJsonPath('message', 'depth');
    }

    public function test_a_save_without_a_difference_logs_nothing(): void
    {
        $a = $this->folder('A');
        $count = DB::table('audit_changes')->count();
        $this->api('PATCH', "/api/folders/{$a}", ['name' => 'a'])->assertOk()->assertJsonPath('folder.name', 'a');
        $this->api('PATCH', "/api/folders/{$a}", ['name' => 'a'])->assertOk();
        // the first changes only the letter case, which is a change; the second changes nothing
        $this->assertSame($count + 1, DB::table('audit_changes')->count());
    }

    // ---- removing ----

    public function test_only_an_empty_folder_can_be_removed(): void
    {
        $a = $this->folder('A');
        $b = $this->folder('B', $a);
        $doc = $this->register(['code' => 'D-1', 'folder_id' => $b]);

        $this->api('DELETE', "/api/folders/{$a}")->assertStatus(409)->assertExactJson(['error' => 'folder_not_empty']);   // a folder inside
        $this->api('DELETE', "/api/folders/{$b}")->assertStatus(409);   // a document inside

        $this->api('PATCH', "/api/documents/{$doc}", ['folder_id' => null])->assertOk();
        $this->api('DELETE', "/api/folders/{$b}")->assertOk();
        $this->api('DELETE', "/api/folders/{$a}")->assertOk();
        $this->assertSame(0, Folder::count());
        $this->api('DELETE', '/api/folders/999')->assertStatus(404);
    }

    // ---- documents in folders ----

    public function test_a_document_is_registered_into_a_folder_and_listed_by_folder(): void
    {
        $contracts = $this->folder('Contracts');
        $inside = $this->folder('2026', $contracts);
        $this->register(['code' => 'TOP']);
        $this->register(['code' => 'IN-1', 'folder_id' => $contracts]);
        $this->register(['code' => 'IN-2', 'folder_id' => $inside]);

        $codes = fn (string $q) => array_column($this->api('GET', '/api/documents'.$q)->assertOk()->json('documents'), 'code');
        $this->assertEqualsCanonicalizing(['TOP', 'IN-1', 'IN-2'], $codes(''));
        $this->assertSame(['TOP'], $codes('?folder=root'));
        $this->assertSame(['IN-1'], $codes("?folder={$contracts}"));
        $this->assertSame(['IN-2'], $codes("?folder={$inside}"));
        $this->api('GET', '/api/documents?folder=abc')->assertStatus(422);

        $this->api('GET', "/api/documents/{$this->docId('IN-2')}")->assertJsonPath('document.folderId', $inside)->assertJsonPath('document.folder', 'Contracts / 2026');
        $this->api('POST', '/api/documents', ['code' => 'X', 'title' => 'X', 'folder_id' => 999, 'files' => [UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')]], self::FORM)->assertStatus(422);
    }

    private function docId(string $code): int
    {
        return Document::where('code', $code)->value('id');
    }

    public function test_a_document_is_moved_between_folders_and_the_move_is_logged_by_folder_name(): void
    {
        $a = $this->folder('A');
        $b = $this->folder('B');
        $doc = $this->register(['code' => 'D-1', 'folder_id' => $a]);

        $this->api('PATCH', "/api/documents/{$doc}", ['folder_id' => $b])->assertOk()->assertJsonPath('document.folder', 'B');

        $entry = collect($this->api('GET', "/api/audits?table=documents&record={$doc}")->json('entries'))->where('column', 'folder_id')->first();
        $this->assertSame(['A', 'B'], [$entry['before'], $entry['after']]);
        $this->assertSame('Folder', $entry['columnLabel']);
        // the folder itself is in Audits too, by its name
        $made = collect($this->api('GET', "/api/audits?table=folders&record={$a}")->json('entries'))->firstWhere('column', 'name');
        $this->assertSame('A', $made['record']);
    }

    public function test_moving_a_document_needs_the_update_tag_for_its_department(): void
    {
        $a = $this->folder('A');
        $doc = $this->register(['code' => 'HR-1', 'department_id' => $this->hr->id]);
        $this->person('viewer', [$this->hr->id => ['view']]);
        $this->person('editor', [$this->hr->id => ['view', 'update']]);

        $this->as('viewer');
        $this->api('PATCH', "/api/documents/{$doc}", ['folder_id' => $a])->assertStatus(403);
        $this->as('editor');
        $this->api('PATCH', "/api/documents/{$doc}", ['folder_id' => $a])->assertOk();
    }

    public function test_everyone_who_may_view_sees_the_folders_with_counts_of_only_what_they_may_see(): void
    {
        $a = $this->folder('A');
        $this->register(['code' => 'HR-1', 'department_id' => $this->hr->id, 'folder_id' => $a]);
        $this->register(['code' => 'ACC-1', 'department_id' => $this->acc->id, 'folder_id' => $a]);
        $this->register(['code' => 'TOP', 'department_id' => $this->hr->id]);
        $this->person('hana', [$this->hr->id => ['view']]);

        $this->as('hana');
        $response = $this->api('GET', '/api/folders')->assertOk();
        $this->assertSame(1, $response->json('folders.0.documents'));
        $this->assertSame(1, $response->json('rootDocuments'));
        $this->as('ana');
        $this->assertSame(2, $this->api('GET', '/api/folders')->json('folders.0.documents'));
    }

    public function test_keeping_folders_needs_the_register_tag_somewhere_and_viewing_them_needs_view(): void
    {
        $a = $this->folder('A');
        $this->person('viewer', [$this->hr->id => ['view', 'download']]);
        $this->person('clerk', [$this->hr->id => ['view', 'register']]);
        $this->person('nobody', []);

        $this->as('viewer');
        $this->api('GET', '/api/folders')->assertOk();
        $this->api('POST', '/api/folders', ['name' => 'X'])->assertStatus(403);
        $this->api('PATCH', "/api/folders/{$a}", ['name' => 'X'])->assertStatus(403);
        $this->api('DELETE', "/api/folders/{$a}")->assertStatus(403);

        $this->as('clerk');
        $this->api('POST', '/api/folders', ['name' => 'X'])->assertCreated();

        $this->as('nobody');
        $this->api('GET', '/api/folders')->assertStatus(403);
    }

    public function test_the_folder_routes_need_a_sign_in(): void
    {
        $this->api('POST', '/api/logout');
        $this->api('GET', '/api/folders')->assertStatus(401);
        $this->api('POST', '/api/folders', ['name' => 'X'])->assertStatus(401);
        $this->api('PATCH', '/api/folders/1', ['name' => 'X'])->assertStatus(401);
        $this->api('DELETE', '/api/folders/1')->assertStatus(401);
    }

    public function test_a_removed_folder_is_still_named_in_audits(): void
    {
        $a = $this->folder('Old stuff');
        $this->api('DELETE', "/api/folders/{$a}")->assertOk();

        $entries = collect($this->api('GET', "/api/audits?table=folders&record={$a}")->json('entries'));
        $this->assertSame('Old stuff', $entries->firstWhere('change', 'Deleted')['record']);
    }
}
