<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** Administration > Roles: making roles and ticking the grid. */
class RolesTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private const FORM = ['X-Documentator' => '1', 'Accept' => 'application/json'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        User::factory()->create(['username' => 'ana', 'name' => 'Ana Reyes']);
        $this->signIn('ana')->assertOk();
    }

    private function as(string $username): void
    {
        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie(config('session.cookie'), '');
        $this->signIn($username)->assertOk();
    }

    private function newRole(string $name = 'HR clerk', ?string $description = 'Works HR papers'): int
    {
        return $this->api('POST', '/api/roles', ['name' => $name, 'description' => $description])->assertCreated()->json('id');
    }

    private function role(int $id): array
    {
        return collect($this->api('GET', '/api/roles')->json('roles'))->firstWhere('id', $id);
    }

    public function test_the_list_has_the_three_carried_over_roles_the_departments_and_the_tags(): void
    {
        $hr = Department::factory()->create(['name' => 'HR']);
        Department::factory()->inactive()->create(['name' => 'Old unit']);
        User::factory()->viewer()->create(['username' => 'vic']);

        $response = $this->api('GET', '/api/roles')->assertOk();

        $this->assertSame(['viewer', 'editor', 'admin'], array_column($response->json('roles'), 'key'));
        $viewer = $response->json('roles.0');
        $this->assertSame(1, $viewer['people']);
        $this->assertFalse($viewer['system']);
        $this->assertSame(['view', 'download'], $viewer['grants']['0']);   // documents with no department
        $this->assertArrayNotHasKey((string) $hr->id, $viewer['grants']);   // a department made after the carry-over: nothing ticked
        $this->assertTrue($response->json('roles.2.system'));
        $this->assertSame(['HR', 'Old unit'], array_column($response->json('departments'), 'name'));   // in use first
        $this->assertSame(['view', 'download', 'register', 'update', 'archive', 'delete'], array_column($response->json('tags'), 'key'));
    }

    public function test_a_role_is_made_with_a_name_a_key_and_nothing_ticked(): void
    {
        $id = $this->newRole('HR clerk');

        $role = $this->role($id);
        $this->assertSame('hr-clerk', $role['key']);
        $this->assertSame('Works HR papers', $role['description']);
        $this->assertSame([], $role['grants']);
        $rows = DB::table('audit_changes')->where('table_name', 'roles')->where('record_id', (string) $id)->pluck('value_after', 'column_name')->all();
        $this->assertSame('HR clerk', $rows['name']);
        $this->assertContains('role.created', DB::table('audit_logs')->pluck('action')->all());
    }

    public function test_names_are_unique_whatever_the_case_and_a_key_never_clashes(): void
    {
        $this->newRole('HR clerk');

        $this->api('POST', '/api/roles', ['name' => 'hr CLERK'])->assertStatus(409)->assertExactJson(['error' => 'role_taken']);
        $this->api('POST', '/api/roles', ['name' => 'Administrator'])->assertStatus(409);
        $this->api('POST', '/api/roles', ['name' => '  '])->assertStatus(422)->assertJsonPath('field', 'name');
        $this->api('POST', '/api/roles', ['name' => str_repeat('a', 61)])->assertStatus(422)->assertJsonPath('field', 'name');

        // "Admin" is a different name from "Administrator" but would make the key "admin", which is taken: it gets another
        $id = $this->newRole('Admin', null);
        $this->assertSame('admin-2', $this->role($id)['key']);
        $this->assertSame('admin', DB::table('roles')->where('is_system', true)->value('key'));
    }

    public function test_the_grid_is_saved_whole_view_comes_with_anything_else_and_the_save_is_logged_in_words(): void
    {
        $hr = Department::factory()->create(['name' => 'HR']);
        $acc = Department::factory()->create(['name' => 'Accounting']);
        $id = $this->newRole();

        $this->api('PATCH', "/api/roles/{$id}", ['grants' => [
            (string) $hr->id => ['download', 'archive'],     // no "view" ticked: it is added
            (string) $acc->id => ['view'],
            '0' => [],                                       // nothing here
        ]])->assertOk();

        $grants = $this->role($id)['grants'];
        $this->assertSame(['view', 'download', 'archive'], $grants[(string) $hr->id]);
        $this->assertSame(['view'], $grants[(string) $acc->id]);
        $this->assertArrayNotHasKey('0', $grants);

        $entry = collect($this->api('GET', "/api/audits?table=roles&record={$id}")->json('entries'))->firstWhere('column', 'grants');
        $this->assertNull($entry['before']);
        $this->assertSame('Accounting: view; HR: view, download, archive', $entry['after']);
        $this->assertSame('Access', $entry['columnLabel']);
        $this->assertSame('HR clerk', $entry['record']);

        // a second save replaces the first, and the log says what it was and what it became
        $this->api('PATCH', "/api/roles/{$id}", ['grants' => ['0' => ['view', 'register']]])->assertOk();
        $this->assertSame(['0' => ['view', 'register']], $this->role($id)['grants']);
        $last = DB::table('audit_changes')->where('table_name', 'roles')->where('column_name', 'grants')->orderByDesc('id')->first();
        $this->assertSame('Accounting: view; HR: view, download, archive', $last->value_before);
        $this->assertSame('No department: view, register', $last->value_after);
    }

    public function test_an_odd_grid_is_refused_and_changes_nothing(): void
    {
        $hr = Department::factory()->create(['name' => 'HR']);
        $id = $this->newRole();
        $this->api('PATCH', "/api/roles/{$id}", ['grants' => [(string) $hr->id => ['view']]])->assertOk();

        $this->api('PATCH', "/api/roles/{$id}", ['grants' => ['9999' => ['view']]])->assertStatus(422)->assertJsonPath('field', 'grants');
        $this->api('PATCH', "/api/roles/{$id}", ['grants' => [(string) $hr->id => ['view', 'fly']]])->assertStatus(422)->assertJsonPath('field', 'grants');
        $this->api('PATCH', "/api/roles/{$id}", ['grants' => [(string) $hr->id => 'view']])->assertStatus(422);

        $this->assertSame([(string) $hr->id => ['view']], $this->role($id)['grants']);
    }

    public function test_a_role_is_renamed_and_described_and_the_people_in_it_keep_it(): void
    {
        $id = $this->newRole();
        User::factory()->create(['username' => 'hana', 'role' => 'hr-clerk']);

        $this->api('PATCH', "/api/roles/{$id}", ['name' => 'People assistant', 'description' => 'New words'])->assertOk();

        $role = $this->role($id);
        $this->assertSame('People assistant', $role['name']);
        $this->assertSame('hr-clerk', $role['key']);   // the key does not move, so nobody loses their role
        $this->assertSame(1, $role['people']);
        $this->assertSame('People assistant', $this->api('GET', '/api/users')->json('users.1.roleLabel'));
        $this->api('PATCH', "/api/roles/{$id}", ['name' => 'viewer'])->assertStatus(409)->assertExactJson(['error' => 'role_taken']);
        $this->api('PATCH', "/api/roles/{$id}", ['name' => 'People Assistant'])->assertOk();   // its own name in another case is fine
    }

    public function test_the_administrator_role_cannot_be_changed_or_removed(): void
    {
        $adminId = DB::table('roles')->where('key', 'admin')->value('id');

        $this->api('PATCH', "/api/roles/{$adminId}", ['grants' => ['0' => ['view']]])->assertStatus(409)->assertExactJson(['error' => 'system_role']);
        $this->api('PATCH', "/api/roles/{$adminId}", ['name' => 'Boss'])->assertStatus(409);
        $this->api('DELETE', "/api/roles/{$adminId}")->assertStatus(409)->assertExactJson(['error' => 'system_role']);

        $this->assertSame('Administrator', DB::table('roles')->where('id', $adminId)->value('name'));
        $this->api('PATCH', '/api/roles/9999', ['name' => 'x'])->assertStatus(404);
    }

    public function test_an_unused_role_can_be_deleted_and_one_in_use_cannot(): void
    {
        $hr = Department::factory()->create(['name' => 'HR']);
        $id = $this->newRole();
        $this->api('PATCH', "/api/roles/{$id}", ['grants' => [(string) $hr->id => ['view']]])->assertOk();
        User::factory()->create(['username' => 'hana', 'role' => 'hr-clerk']);

        $this->api('DELETE', "/api/roles/{$id}")->assertStatus(409)->assertExactJson(['error' => 'role_in_use', 'people' => 1]);
        $this->assertSame(1, DB::table('roles')->where('id', $id)->count());

        User::where('username', 'hana')->update(['role' => 'viewer']);
        $this->api('DELETE', "/api/roles/{$id}")->assertOk();

        $this->assertSame(0, DB::table('roles')->where('id', $id)->count());
        $this->assertSame(0, DB::table('role_grants')->where('role_id', $id)->count());
        $deleted = DB::table('audit_changes')->where('table_name', 'roles')->where('change_type', 'Deleted')->pluck('value_before', 'column_name')->all();
        $this->assertSame('HR clerk', $deleted['name']);
        $this->assertSame('HR: view', $deleted['grants']);
        $this->assertContains('role.deleted', DB::table('audit_logs')->pluck('action')->all());
        $shown = collect($this->api('GET', "/api/audits?table=roles&record={$id}")->json('entries'));
        $this->assertSame('Deleted', $shown->first()['change']);
        $this->assertNull($shown->first()['record']);   // it is gone, the id is what is left
    }

    public function test_a_role_made_here_can_be_given_to_a_person_and_decides_what_they_reach(): void
    {
        $hr = Department::factory()->create(['name' => 'HR']);
        $acc = Department::factory()->create(['name' => 'Accounting']);
        $hrDoc = $this->post('/api/documents', ['code' => 'HR-1', 'title' => 'HR', 'department_id' => $hr->id, 'files' => [UploadedFile::fake()->createWithContent('a.txt', 'x')]], self::FORM)->assertCreated()->json('document.id');
        $this->post('/api/documents', ['code' => 'ACC-1', 'title' => 'Acc', 'department_id' => $acc->id, 'files' => [UploadedFile::fake()->createWithContent('b.txt', 'x')]], self::FORM)->assertCreated();
        $id = $this->newRole();
        $this->api('PATCH', "/api/roles/{$id}", ['grants' => [(string) $hr->id => ['view', 'download']]])->assertOk();

        $this->assertSame('invalid', $this->api('POST', '/api/users', ['username' => 'hana', 'name' => 'Hana', 'role' => 'nope', 'password' => 'starter-pass-1'])->assertStatus(422)->json('error'));
        $this->api('POST', '/api/users', ['username' => 'hana', 'name' => 'Hana', 'role' => 'hr-clerk', 'password' => 'starter-pass-1'])->assertCreated()->assertJsonPath('user.roleLabel', 'HR clerk');
        $this->assertContains('hr-clerk', array_column($this->api('GET', '/api/users')->json('roles'), 'key'));
        User::where('username', 'hana')->update(['must_change_password' => false]);   // skip the first-sign-in step here

        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie(config('session.cookie'), '');
        $this->signIn('hana', 'starter-pass-1')->assertOk();
        $this->assertSame(['HR-1'], array_column($this->api('GET', '/api/documents')->json('documents'), 'code'));
        $this->api('GET', "/api/documents/{$hrDoc}")->assertOk()->assertJsonPath('document.can.download', true)->assertJsonPath('document.can.update', false);
    }

    public function test_only_an_administrator_manages_roles(): void
    {
        User::factory()->editor()->create(['username' => 'edie']);
        User::factory()->viewer()->create(['username' => 'vic']);

        foreach (['edie', 'vic'] as $name) {
            $this->as($name);
            $this->api('GET', '/api/roles')->assertStatus(403)->assertExactJson(['error' => 'forbidden']);
            $this->api('POST', '/api/roles', ['name' => 'Sneaky'])->assertStatus(403);
            $this->api('PATCH', '/api/roles/1', ['name' => 'Sneaky'])->assertStatus(403);
            $this->api('DELETE', '/api/roles/1')->assertStatus(403);
            $this->api('DELETE', '/api/roles/999')->assertStatus(403);   // not a 404
        }
        $this->assertSame(3, DB::table('roles')->count());
    }
}
