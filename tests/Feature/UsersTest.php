<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** Roles and what they may do, the Users API, and the password a new account must change at first sign-in. */
class UsersTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private const FORM = ['X-Documentator' => '1', 'Accept' => 'application/json'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function as(string $username, string $password = 'correct-horse'): void
    {
        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie(config('session.cookie'), '');
        $this->signIn($username, $password)->assertOk();
    }

    private function newUser(array $fields = []): TestResponse
    {
        return $this->api('POST', '/api/users', $fields + ['username' => 'cara', 'name' => 'Cara Lim', 'role' => 'editor', 'password' => 'starter-pass-1']);
    }

    private function uploadDocument(): int
    {
        return $this->post('/api/documents', ['code' => 'D-1', 'title' => 'Doc', 'files' => [UploadedFile::fake()->createWithContent('a.txt', 'x')]], self::FORM)->assertCreated()->json('document.id');
    }

    // ---- roles ----

    public function test_each_role_gets_exactly_its_permissions(): void
    {
        User::factory()->viewer()->create(['username' => 'vic']);
        User::factory()->editor()->create(['username' => 'edie']);
        User::factory()->create(['username' => 'ana']);

        $this->as('vic');
        $this->assertSame(['documentator.document.view', 'documentator.file.download'], $this->api('GET', '/api/session')->json('user.permissions'));
        $this->as('edie');
        $this->assertContains('documentator.version.create', $this->api('GET', '/api/session')->json('user.permissions'));
        $this->assertNotContains('documentator.archive.manage', $this->api('GET', '/api/session')->json('user.permissions'));
        $this->as('ana');
        $this->assertContains('documentator.user.manage', $this->api('GET', '/api/session')->json('user.permissions'));
        $this->api('GET', '/api/session')->assertJsonPath('user.role', 'admin')->assertJsonPath('user.roleLabel', 'Administrator');
    }

    public function test_a_viewer_can_read_but_nothing_else(): void
    {
        User::factory()->create(['username' => 'ana']);
        $this->as('ana');
        $id = $this->uploadDocument();
        $fileId = DB::table('document_files')->value('id');
        User::factory()->viewer()->create(['username' => 'vic']);
        $this->as('vic');

        $this->api('GET', '/api/documents')->assertOk();
        $this->api('GET', "/api/documents/{$id}")->assertOk();
        $this->api('GET', "/api/files/{$fileId}")->assertOk();

        $this->api('GET', '/api/documents/suggest-code')->assertStatus(403)->assertExactJson(['error' => 'forbidden']);
        $this->post('/api/documents', ['code' => 'X', 'title' => 'X', 'files' => [UploadedFile::fake()->createWithContent('b.txt', 'y')]], self::FORM)->assertStatus(403);
        $this->post("/api/documents/{$id}/versions", ['files' => [UploadedFile::fake()->createWithContent('b.txt', 'y')]], self::FORM)->assertStatus(403);
        $this->api('GET', '/api/archive')->assertStatus(403);
        $this->api('GET', '/api/archive/rules')->assertStatus(403);
        $this->api('PUT', '/api/archive/rules/idle', ['enabled' => true, 'amount' => 1, 'unit' => 'days'])->assertStatus(403);
        $this->api('POST', '/api/archive/run')->assertStatus(403);
        $this->api('POST', '/api/versions/1/archive')->assertStatus(403);
        $this->api('GET', '/api/audits')->assertStatus(403);
        $this->api('GET', '/api/users')->assertStatus(403);
        $this->api('POST', '/api/users/1/password', ['password' => 'whatever-1'])->assertStatus(403);

        $this->assertSame(1, DB::table('documents')->count());
    }

    public function test_an_editor_can_register_and_add_versions_but_not_archive_audit_or_manage_users(): void
    {
        User::factory()->editor()->create(['username' => 'edie']);
        $this->as('edie');

        $id = $this->uploadDocument();
        $this->post("/api/documents/{$id}/versions", ['files' => [UploadedFile::fake()->createWithContent('b.txt', 'y')]], self::FORM)->assertCreated();
        $this->api('GET', '/api/documents/suggest-code')->assertOk();

        $this->api('GET', '/api/archive')->assertStatus(403);
        $this->api('GET', '/api/audits')->assertStatus(403);
        $this->api('GET', '/api/users')->assertStatus(403);
    }

    public function test_a_person_without_the_right_cannot_tell_from_a_404_whether_a_record_exists(): void
    {
        User::factory()->viewer()->create(['username' => 'vic']);
        $this->as('vic');

        $this->api('POST', '/api/versions/999/archive')->assertStatus(403);   // not 404
        $this->api('PATCH', '/api/users/999', ['name' => 'x'])->assertStatus(403);
    }

    // ---- the Users API ----

    public function test_an_administrator_makes_an_account_that_must_change_its_password(): void
    {
        User::factory()->create(['username' => 'ana', 'name' => 'Ana Reyes']);
        $this->as('ana');

        $created = $this->newUser(['username' => '  Cara ']);

        $created->assertCreated()->assertJsonPath('user.username', 'cara')->assertJsonPath('user.name', 'Cara Lim')->assertJsonPath('user.role', 'editor')
            ->assertJsonPath('user.mustChangePassword', true)->assertJsonPath('user.active', true);
        $user = User::findByUsername('cara');
        $this->assertTrue(Hash::check('starter-pass-1', $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);

        $rows = DB::table('audit_changes')->where('table_name', 'users')->where('record_id', (string) $user->id)->pluck('value_after', 'column_name')->all();
        $this->assertSame('cara', $rows['username']);
        $this->assertSame('editor', $rows['role']);
        $this->assertSame('(hidden)', $rows['password']);
        $this->assertSame('true', $rows['must_change_password']);
        $this->assertStringNotContainsString('starter-pass', json_encode(DB::table('audit_changes')->get()));
        $this->assertSame('ana', DB::table('audit_changes')->where('record_id', (string) $user->id)->value('actor_username'));
        $this->assertContains('user.created', DB::table('audit_logs')->pluck('action')->all());
    }

    public function test_the_list_has_the_accounts_and_the_roles_to_choose_from(): void
    {
        User::factory()->create(['username' => 'ana', 'name' => 'Ana Reyes']);
        User::factory()->viewer()->create(['username' => 'vic', 'name' => 'Vic Tan']);
        $this->as('ana');

        $response = $this->api('GET', '/api/users')->assertOk();

        $this->assertSame(['Ana Reyes', 'Vic Tan'], array_column($response->json('users'), 'name'));
        $this->assertSame(['viewer', 'editor', 'admin'], array_column($response->json('roles'), 'key'));
        $this->assertStringContainsString('registers documents', $response->json('roles.1.description'));
        $this->assertStringNotContainsString('hash', json_encode($response->json()));
        $this->assertArrayNotHasKey('password', $response->json('users.0'));
    }

    public function test_a_bad_new_account_is_refused_naming_the_field(): void
    {
        User::factory()->create(['username' => 'ana']);
        $this->as('ana');

        $this->newUser(['username' => 'a b'])->assertStatus(422)->assertJsonPath('field', 'username');
        $this->newUser(['username' => 'ab'])->assertStatus(422)->assertJsonPath('field', 'username');
        $this->newUser(['name' => ''])->assertStatus(422)->assertJsonPath('field', 'name');
        $this->newUser(['role' => 'boss'])->assertStatus(422)->assertJsonPath('field', 'role');
        $this->newUser(['password' => 'short'])->assertStatus(422)->assertJsonPath('field', 'password')->assertJsonPath('message', 'password_short');
        $this->newUser(['username' => 'cara', 'password' => 'CARA'])->assertStatus(422)->assertJsonPath('message', 'password_short');
        $this->newUser(['username' => 'caracara', 'password' => 'CaraCara'])->assertStatus(422)->assertJsonPath('message', 'password_username');
        $this->newUser(['username' => 'ANA'])->assertStatus(409)->assertExactJson(['error' => 'username_taken']);

        $this->assertSame(1, User::count());
    }

    public function test_a_new_account_can_only_choose_its_own_password_until_it_has(): void
    {
        User::factory()->create(['username' => 'ana']);
        $this->as('ana');
        $this->newUser()->assertCreated();

        $this->as('cara', 'starter-pass-1');
        $this->api('GET', '/api/session')->assertOk()->assertJsonPath('user.mustChangePassword', true);
        $this->api('GET', '/api/documents')->assertStatus(403)->assertExactJson(['error' => 'password_change_required']);
        $this->api('GET', '/api/users')->assertStatus(403);
        $this->post('/api/documents', ['code' => 'X', 'title' => 'X', 'files' => [UploadedFile::fake()->createWithContent('b.txt', 'y')]], self::FORM)
            ->assertStatus(403)->assertExactJson(['error' => 'password_change_required']);

        $this->api('POST', '/api/password', ['current' => 'nope-nope-1', 'password' => 'my-own-pass-1'])->assertStatus(422)->assertJsonPath('field', 'current');
        $this->api('POST', '/api/password', ['current' => 'starter-pass-1', 'password' => 'starter-pass-1'])->assertStatus(422)->assertJsonPath('message', 'password_same');
        $this->api('POST', '/api/password', ['current' => 'starter-pass-1', 'password' => 'short'])->assertStatus(422)->assertJsonPath('message', 'password_short');
        $this->api('GET', '/api/documents')->assertStatus(403);   // still stuck

        $this->api('POST', '/api/password', ['current' => 'starter-pass-1', 'password' => 'my-own-pass-1'])->assertOk()->assertJsonPath('user.mustChangePassword', false);

        $this->api('GET', '/api/documents')->assertOk();   // the same session now works
        $this->assertFalse(User::findByUsername('cara')->must_change_password);
        $this->assertTrue(Hash::check('my-own-pass-1', User::findByUsername('cara')->password));
        $this->assertContains('user.password-changed', DB::table('audit_logs')->pluck('action')->all());
        $changed = DB::table('audit_changes')->where('table_name', 'users')->where('change_type', 'Updated')->pluck('column_name')->all();
        $this->assertContains('must_change_password', $changed);
        $this->assertContains('password', $changed);
        $this->assertStringNotContainsString('my-own-pass', json_encode(DB::table('audit_changes')->get()));
    }

    public function test_nobody_changes_their_own_password_otherwise(): void
    {
        User::factory()->create(['username' => 'ana']);
        $this->as('ana');

        $this->api('POST', '/api/password', ['current' => 'correct-horse', 'password' => 'brand-new-pass'])->assertStatus(403)->assertExactJson(['error' => 'forbidden']);

        $this->assertTrue(Hash::check('correct-horse', User::findByUsername('ana')->password));
    }

    public function test_an_administrator_sets_a_password_which_must_then_be_changed_and_ends_their_sessions(): void
    {
        User::factory()->create(['username' => 'ana']);
        $vic = User::factory()->viewer()->create(['username' => 'vic']);
        $this->as('ana');
        DB::table('sessions')->insert(['id' => 'vics-session', 'user_id' => $vic->id, 'payload' => '', 'last_activity' => time()]);

        $this->api('POST', "/api/users/{$vic->id}/password", ['password' => 'reset-pass-77'])->assertOk()->assertJsonPath('user.mustChangePassword', true);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $vic->id)->count());
        $fresh = User::findByUsername('vic');
        $this->assertTrue(Hash::check('reset-pass-77', $fresh->password));
        $this->assertTrue($fresh->must_change_password);
        $this->assertContains('user.password-set', DB::table('audit_logs')->pluck('action')->all());
        $this->assertStringNotContainsString('reset-pass', json_encode(DB::table('audit_changes')->get()));

        $this->as('vic', 'reset-pass-77');
        $this->api('GET', '/api/documents')->assertStatus(403)->assertJsonPath('error', 'password_change_required');
    }

    public function test_setting_a_password_is_refused_for_yourself_a_onehub_account_and_a_weak_password(): void
    {
        $ana = User::factory()->create(['username' => 'ana']);
        $oh = User::factory()->create(['username' => 'olga', 'auth_source' => User::AUTH_ONEHUB, 'external_id' => 'oh-1']);
        $vic = User::factory()->viewer()->create(['username' => 'vic']);
        $this->as('ana');

        $this->api('POST', "/api/users/{$ana->id}/password", ['password' => 'reset-pass-77'])->assertStatus(409)->assertExactJson(['error' => 'own_account']);
        $this->api('POST', "/api/users/{$oh->id}/password", ['password' => 'reset-pass-77'])->assertStatus(409)->assertExactJson(['error' => 'not_local']);
        $this->api('POST', "/api/users/{$vic->id}/password", ['password' => 'short'])->assertStatus(422)->assertJsonPath('message', 'password_short');
        $this->api('POST', '/api/users/9999/password', ['password' => 'reset-pass-77'])->assertStatus(404);

        $this->assertTrue(Hash::check('correct-horse', User::findByUsername('vic')->password));
    }

    public function test_name_and_role_change_and_each_change_is_logged_with_the_role_in_words(): void
    {
        User::factory()->create(['username' => 'ana']);
        $vic = User::factory()->viewer()->create(['username' => 'vic', 'name' => 'Vic Tan']);
        $this->as('ana');

        $this->api('PATCH', "/api/users/{$vic->id}", ['name' => '  Victor Tan ', 'role' => 'editor'])->assertOk()
            ->assertJsonPath('user.name', 'Victor Tan')->assertJsonPath('user.role', 'editor')->assertJsonPath('user.roleLabel', 'Editor');

        $entries = collect($this->api('GET', "/api/audits?table=users&record={$vic->id}")->json('entries'))->where('change', 'Updated');
        $this->assertEqualsCanonicalizing(['name', 'role'], $entries->pluck('column')->all());
        $role = $entries->firstWhere('column', 'role');
        $this->assertSame('Viewer', $role['before']);
        $this->assertSame('Editor', $role['after']);
        $this->assertSame('Role', $role['columnLabel']);

        $this->api('PATCH', "/api/users/{$vic->id}", ['role' => 'boss'])->assertStatus(422)->assertJsonPath('field', 'role');
        $this->api('PATCH', "/api/users/{$vic->id}", ['name' => ''])->assertStatus(422)->assertJsonPath('field', 'name');
        $this->api('PATCH', "/api/users/{$vic->id}", [])->assertOk();   // nothing to change is not an error
        $this->assertSame(2, $entries->count());
    }

    public function test_switching_an_account_off_ends_its_sessions_and_on_lets_it_back_in(): void
    {
        User::factory()->create(['username' => 'ana']);
        $vic = User::factory()->viewer()->create(['username' => 'vic']);
        $this->as('ana');
        DB::table('sessions')->insert(['id' => 'vics-session', 'user_id' => $vic->id, 'payload' => '', 'last_activity' => time()]);

        $this->api('PATCH', "/api/users/{$vic->id}", ['active' => false])->assertOk()->assertJsonPath('user.active', false);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $vic->id)->count());
        $this->signIn('vic')->assertStatus(403)->assertExactJson(['error' => 'inactive']);

        $this->api('PATCH', "/api/users/{$vic->id}", ['active' => true])->assertOk();
        $this->as('vic');   // back in
        $this->assertSame(['user.switched-off', 'user.switched-on'], DB::table('audit_logs')->whereIn('action', ['user.switched-off', 'user.switched-on'])->orderBy('id')->pluck('action')->all());
        $active = DB::table('audit_changes')->where('table_name', 'users')->where('column_name', 'active')->where('change_type', 'Updated')->orderBy('id')->get();
        $this->assertSame(['false', 'true'], $active->pluck('value_after')->all());
    }

    public function test_an_administrator_cannot_lock_themselves_out_or_demote_themselves(): void
    {
        $ana = User::factory()->create(['username' => 'ana']);
        $this->as('ana');

        $this->api('PATCH', "/api/users/{$ana->id}", ['active' => false])->assertStatus(409)->assertExactJson(['error' => 'own_account']);
        $this->api('PATCH', "/api/users/{$ana->id}", ['role' => 'viewer'])->assertStatus(409)->assertExactJson(['error' => 'own_account']);
        $this->api('PATCH', "/api/users/{$ana->id}", ['name' => 'Ana R.'])->assertOk();   // other changes to yourself are fine

        // another administrator can demote her; then the one left cannot remove themselves either, so one always remains
        $ben = User::factory()->create(['username' => 'ben']);
        $this->as('ben');
        $this->api('PATCH', "/api/users/{$ana->id}", ['role' => 'viewer'])->assertOk();
        $this->as('ana');
        $this->api('GET', '/api/users')->assertStatus(403);   // no longer an administrator

        $this->as('ben');
        $this->api('PATCH', "/api/users/{$ben->id}", ['active' => false])->assertStatus(409);
        $this->assertSame(1, User::where('role', 'admin')->where('active', true)->count());
    }

    public function test_every_user_route_needs_a_sign_in(): void
    {
        $this->api('GET', '/api/users')->assertStatus(401);
        $this->api('POST', '/api/users', [])->assertStatus(401);
        $this->api('PATCH', '/api/users/1', [])->assertStatus(401);
        $this->api('POST', '/api/users/1/password', [])->assertStatus(401);
        $this->api('POST', '/api/password', [])->assertStatus(401);
    }

    // ---- the command line ----

    public function test_the_command_makes_an_administrator_unless_told_otherwise(): void
    {
        $this->artisan('documentator:set-password', ['username' => 'first', '--create' => true])
            ->expectsQuestion('Password', 'long-enough-1')->expectsQuestion('Password again', 'long-enough-1')->assertSuccessful();
        $this->artisan('documentator:set-password', ['username' => 'reader', '--create' => true, '--role' => 'viewer'])
            ->expectsQuestion('Password', 'long-enough-1')->expectsQuestion('Password again', 'long-enough-1')->assertSuccessful();

        $this->assertSame('admin', User::findByUsername('first')->role);
        $this->assertSame('viewer', User::findByUsername('reader')->role);
        $this->assertFalse(User::findByUsername('first')->must_change_password);   // made by someone at the server, who knows it
        $this->artisan('documentator:set-password', ['username' => 'odd', '--create' => true, '--role' => 'boss'])->assertFailed();
        $this->assertNull(User::findByUsername('odd'));
    }
}
