<?php

namespace Tests\Feature;

use App\Auth\Access;
use App\Models\Department;
use App\Models\DocumentFile;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** A role's grid: what a person may do in each department, enforced by the server. */
class DepartmentAccessTest extends TestCase
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
    }

    private function as(string $username): void
    {
        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie(config('session.cookie'), '');
        $this->signIn($username)->assertOk();
    }

    /** A role with this grid (department id, 0 for none => short tag names) and a person who has it. */
    private function person(string $username, array $grid, ?string $role = null): User
    {
        $key = $role ?? 'role-'.$username;
        $id = DB::table('roles')->insertGetId(['key' => $key, 'name' => ucfirst($key), 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($grid as $department => $tags) {
            foreach ($tags as $tag) {
                DB::table('role_grants')->insert(['role_id' => $id, 'department_id' => $department, 'permission' => Access::TAGS[$tag]]);
            }
        }

        return User::factory()->create(['username' => $username, 'name' => ucfirst($username), 'role' => $key]);
    }

    private function register(string $code, ?Department $department, string $content = 'x'): TestResponse|\Illuminate\Testing\TestResponse
    {
        return $this->post('/api/documents', ['code' => $code, 'title' => $code, 'department_id' => $department?->id,
            'files' => [UploadedFile::fake()->createWithContent("{$code}.txt", $content)]], self::FORM);
    }

    /** As the administrator: an HR document, an Accounting one and one with no department. Returns their ids. */
    private function documents(): array
    {
        $this->as('ana');

        return [
            'hr' => $this->register('HR-1', $this->hr)->assertCreated()->json('document.id'),
            'acc' => $this->register('ACC-1', $this->acc)->assertCreated()->json('document.id'),
            'none' => $this->register('NONE-1', null)->assertCreated()->json('document.id'),
        ];
    }

    private function codes(): array
    {
        return array_column($this->api('GET', '/api/documents')->assertOk()->json('documents'), 'code');
    }

    public function test_a_person_sees_only_the_departments_their_role_may_view(): void
    {
        $ids = $this->documents();
        $this->person('hana', [$this->hr->id => ['view']]);
        $this->person('nora', [Access::NO_DEPARTMENT => ['view']]);
        $this->person('both', [$this->hr->id => ['view'], $this->acc->id => ['view']]);

        $this->as('hana');
        $this->assertSame(['HR-1'], $this->codes());
        $this->api('GET', "/api/documents/{$ids['hr']}")->assertOk();
        $this->api('GET', "/api/documents/{$ids['acc']}")->assertStatus(403)->assertExactJson(['error' => 'forbidden']);
        $this->api('GET', "/api/documents/{$ids['none']}")->assertStatus(403);

        $this->as('nora');
        $this->assertSame(['NONE-1'], $this->codes());
        $this->api('GET', "/api/documents/{$ids['none']}")->assertOk();

        $this->as('both');
        $this->assertEqualsCanonicalizing(['HR-1', 'ACC-1'], $this->codes());

        $this->as('ana');
        $this->assertEqualsCanonicalizing(['HR-1', 'ACC-1', 'NONE-1'], $this->codes());   // the administrator sees everything
    }

    public function test_the_list_of_types_only_comes_from_documents_the_person_may_see(): void
    {
        $this->as('ana');
        $this->post('/api/documents', ['code' => 'A', 'title' => 'A', 'doc_type' => 'Board resolution', 'department_id' => $this->acc->id,
            'files' => [UploadedFile::fake()->createWithContent('a.txt', 'x')]], self::FORM)->assertCreated();
        $this->post('/api/documents', ['code' => 'B', 'title' => 'B', 'doc_type' => 'Permit', 'department_id' => $this->hr->id,
            'files' => [UploadedFile::fake()->createWithContent('b.txt', 'x')]], self::FORM)->assertCreated();
        $this->person('hana', [$this->hr->id => ['view']]);

        $this->as('hana');
        $this->assertSame(['Permit'], $this->api('GET', '/api/documents')->json('types'));
    }

    public function test_download_is_its_own_tag_and_a_person_who_cannot_see_a_department_cannot_open_its_files(): void
    {
        $ids = $this->documents();
        $hrFile = DocumentFile::whereHas('version', fn ($q) => $q->where('document_id', $ids['hr']))->value('id');
        $accFile = DocumentFile::whereHas('version', fn ($q) => $q->where('document_id', $ids['acc']))->value('id');
        $this->person('reader', [$this->hr->id => ['view']]);
        $this->person('getter', [$this->hr->id => ['view', 'download']]);

        $this->as('reader');
        $this->api('GET', "/api/documents/{$ids['hr']}")->assertOk()->assertJsonPath('document.can.download', false);   // sees the record
        $this->api('GET', "/api/files/{$hrFile}")->assertStatus(403);   // but not its files
        $this->api('GET', "/api/files/{$hrFile}?view=1")->assertStatus(403);

        $this->as('getter');
        $this->api('GET', "/api/documents/{$ids['hr']}")->assertJsonPath('document.can.download', true);
        $this->api('GET', "/api/files/{$hrFile}")->assertOk();
        $this->api('GET', "/api/files/{$accFile}")->assertStatus(403);   // another department's file
        $this->assertNull(DocumentFile::findOrFail($accFile)->last_downloaded_at);   // and it is not marked as used
    }

    public function test_ticking_a_tag_alone_still_means_the_person_can_see_the_department(): void
    {
        // a role row with only "download" (made directly, not through the screen) gives no sight of the document
        $ids = $this->documents();
        $hrFile = DocumentFile::whereHas('version', fn ($q) => $q->where('document_id', $ids['hr']))->value('id');
        $this->person('odd', [$this->hr->id => ['download']]);

        $this->as('odd');
        $this->api('GET', '/api/documents')->assertStatus(403);   // nothing to view anywhere, so not even the list
        $this->api('GET', "/api/documents/{$ids['hr']}")->assertStatus(403);
        $this->api('GET', "/api/files/{$hrFile}")->assertOk();   // the tag itself is what the file route checks
    }

    public function test_register_only_in_the_departments_the_role_allows_and_the_form_offers_only_those(): void
    {
        $this->documents();
        $this->person('rita', [$this->hr->id => ['view', 'register']]);
        $this->person('nina', [Access::NO_DEPARTMENT => ['view', 'register']]);

        $this->as('rita');
        $list = $this->api('GET', '/api/documents')->assertOk();
        $this->assertSame([['id' => $this->hr->id, 'name' => 'HR']], $list->json('departments'));
        $this->assertFalse($list->json('canRegisterNone'));
        $this->api('GET', '/api/documents/suggest-code')->assertOk();
        $this->register('HR-2', $this->hr)->assertCreated();
        $this->register('ACC-2', $this->acc)->assertStatus(403)->assertExactJson(['error' => 'forbidden']);
        $this->register('NONE-2', null)->assertStatus(403);
        $this->assertSame(0, DB::table('documents')->whereIn('code', ['ACC-2', 'NONE-2'])->count());

        $this->as('nina');
        $this->assertTrue($this->api('GET', '/api/documents')->json('canRegisterNone'));
        $this->assertSame([], $this->api('GET', '/api/documents')->json('departments'));
        $this->register('NONE-3', null)->assertCreated();
        $this->register('HR-3', $this->hr)->assertStatus(403);
    }

    public function test_update_means_uploading_a_new_version_and_only_in_the_departments_the_role_allows(): void
    {
        $ids = $this->documents();
        $this->person('ula', [$this->hr->id => ['view', 'update']]);
        $upload = fn (int $id) => $this->post("/api/documents/{$id}/versions", ['files' => [UploadedFile::fake()->createWithContent('n.txt', 'new')]], self::FORM);

        $this->as('ula');
        $this->api('GET', "/api/documents/{$ids['hr']}")->assertJsonPath('document.can.update', true);
        $upload($ids['hr'])->assertCreated()->assertJsonPath('document.rev', 1);
        $upload($ids['acc'])->assertStatus(403)->assertExactJson(['error' => 'forbidden']);
        $this->assertSame(0, DocumentVersion::where('document_id', $ids['acc'])->where('rev_no', 1)->count());
    }

    public function test_archive_lists_and_changes_only_the_departments_the_role_may_archive_in(): void
    {
        $ids = $this->documents();
        // an old version in each department
        foreach (['hr', 'acc'] as $key) {
            $this->post("/api/documents/{$ids[$key]}/versions", ['files' => [UploadedFile::fake()->createWithContent('v2.txt', 'two')]], self::FORM)->assertCreated();
        }
        $hrOld = DocumentVersion::where('document_id', $ids['hr'])->where('rev_no', 0)->value('id');
        $accOld = DocumentVersion::where('document_id', $ids['acc'])->where('rev_no', 0)->value('id');
        $this->person('arne', [$this->hr->id => ['view', 'archive']]);

        $this->as('arne');
        $this->assertSame([$hrOld], array_column($this->api('GET', '/api/archive')->assertOk()->json('versions'), 'id'));
        $this->api('GET', "/api/documents/{$ids['hr']}")->assertJsonPath('document.can.archive', true);
        $this->api('POST', "/api/versions/{$accOld}/archive")->assertStatus(403)->assertExactJson(['error' => 'forbidden']);
        $this->api('POST', "/api/versions/{$hrOld}/archive")->assertOk();
        $this->api('POST', "/api/versions/{$accOld}/restore")->assertStatus(403);
        $this->api('POST', "/api/versions/{$hrOld}/restore")->assertOk();

        // the rules touch every department, so the archive tag does not open them
        $this->api('GET', '/api/archive/rules')->assertStatus(403);
        $this->api('PUT', '/api/archive/rules/old', ['enabled' => true, 'amount' => 0, 'unit' => 'days'])->assertStatus(403);
        $this->api('POST', '/api/archive/run')->assertStatus(403);
        $this->assertNull(DocumentVersion::findOrFail($accOld)->archived_at);
    }

    public function test_a_department_made_later_starts_with_nothing_ticked_for_anyone_but_the_administrator(): void
    {
        $this->as('ana');
        $new = Department::factory()->create(['name' => 'Legal']);
        $id = $this->register('LEG-1', $new)->assertCreated()->json('document.id');
        User::factory()->editor()->create(['username' => 'edie']);   // the carried-over editor role
        User::factory()->viewer()->create(['username' => 'vic']);

        foreach (['edie', 'vic'] as $name) {
            $this->as($name);
            $this->assertNotContains('LEG-1', $this->codes());
            $this->api('GET', "/api/documents/{$id}")->assertStatus(403);
        }
        $this->as('ana');
        $this->assertContains('LEG-1', $this->codes());
    }

    public function test_the_carried_over_viewer_and_editor_roles_keep_working_on_documents_with_no_department(): void
    {
        $ids = $this->documents();
        $viewer = User::factory()->viewer()->create(['username' => 'vic']);
        $editor = User::factory()->editor()->create(['username' => 'edie']);

        $this->as('vic');
        $this->assertSame(['NONE-1'], $this->codes());
        $this->api('GET', '/api/files/'.DocumentFile::whereHas('version', fn ($q) => $q->where('document_id', $ids['none']))->value('id'))->assertOk();
        $this->register('X', null)->assertStatus(403);

        $this->as('edie');
        $this->register('NONE-2', null)->assertCreated();
        $this->post("/api/documents/{$ids['none']}/versions", ['files' => [UploadedFile::fake()->createWithContent('n.txt', 'new')]], self::FORM)->assertCreated();
        unset($viewer, $editor);
    }

    public function test_a_role_with_no_grants_anywhere_reaches_nothing_and_the_session_says_so(): void
    {
        $this->documents();
        $this->person('nobody', []);

        $this->as('nobody');
        $this->api('GET', '/api/session')->assertOk()->assertJsonPath('user.permissions', []);
        $this->api('GET', '/api/documents')->assertStatus(403)->assertExactJson(['error' => 'forbidden']);
        $this->api('GET', '/api/archive')->assertStatus(403);
        $this->register('X', null)->assertStatus(403);
    }

    public function test_the_session_lists_what_the_person_may_do_somewhere(): void
    {
        $this->person('mix', [$this->hr->id => ['view', 'download'], $this->acc->id => ['view', 'archive']]);

        $this->as('mix');
        $this->assertSame(
            ['documentator.document.view', 'documentator.file.download', 'documentator.version.archive'],
            $this->api('GET', '/api/session')->json('user.permissions'),
        );
        $this->as('ana');
        $permissions = $this->api('GET', '/api/session')->json('user.permissions');
        $this->assertContains('documentator.role.manage', $permissions);
        $this->assertContains('documentator.version.archive', $permissions);
    }
}
