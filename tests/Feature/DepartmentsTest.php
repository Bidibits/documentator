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

/** Administration > Departments, and documents pointing at them. */
class DepartmentsTest extends TestCase
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

    private function register(string $code, ?int $department): int
    {
        return $this->post('/api/documents', ['code' => $code, 'title' => $code, 'department_id' => $department, 'files' => [UploadedFile::fake()->createWithContent('a.txt', 'x')]], self::FORM)
            ->assertCreated()->json('document.id');
    }

    public function test_an_administrator_adds_a_department_and_it_is_logged(): void
    {
        $this->api('POST', '/api/departments', ['name' => '  Human   Resources '])->assertCreated()
            ->assertJsonPath('department.name', 'Human Resources')->assertJsonPath('department.active', true)->assertJsonPath('department.documents', 0);

        $this->assertSame(['Human Resources'], Department::pluck('name')->all());
        $rows = DB::table('audit_changes')->where('table_name', 'departments')->orderBy('id')->get()
            ->map(fn ($r) => "{$r->column_name}: ".($r->value_before ?? '-').' > '.($r->value_after ?? '-')." ({$r->change_type})")->all();
        $this->assertContains('name: - > Human Resources (Created)', $rows);
        $this->assertContains('active: - > true (Created)', $rows);
        $this->assertContains('department.created', DB::table('audit_logs')->pluck('action')->all());
        $shown = collect($this->api('GET', '/api/audits?table=departments')->json('entries'));
        $this->assertSame('Human Resources', $shown->first()['record']);
        $this->assertSame('Departments', $shown->first()['tableLabel']);
    }

    public function test_the_list_counts_each_departments_documents(): void
    {
        $hr = Department::factory()->create(['name' => 'HR']);
        Department::factory()->create(['name' => 'Accounting']);
        $this->register('A', $hr->id);
        $this->register('B', $hr->id);
        $this->register('C', null);

        $list = $this->api('GET', '/api/departments')->assertOk()->json('departments');

        $this->assertSame(['Accounting', 'HR'], array_column($list, 'name'));
        $this->assertSame([0, 2], array_column($list, 'documents'));
    }

    public function test_a_name_cannot_be_used_twice_whatever_its_case_and_cannot_be_empty(): void
    {
        $hr = Department::factory()->create(['name' => 'HR']);

        $this->api('POST', '/api/departments', ['name' => 'hr'])->assertStatus(409)->assertExactJson(['error' => 'department_taken']);
        $this->api('POST', '/api/departments', ['name' => '   '])->assertStatus(422)->assertJsonPath('field', 'name');
        $this->api('POST', '/api/departments', ['name' => str_repeat('a', 101)])->assertStatus(422)->assertJsonPath('field', 'name');
        $this->api('POST', '/api/departments', [])->assertStatus(422)->assertJsonPath('field', 'name');

        $other = Department::factory()->create(['name' => 'Accounting']);
        $this->api('PATCH', "/api/departments/{$other->id}", ['name' => 'Hr'])->assertStatus(409);
        $this->api('PATCH', "/api/departments/{$hr->id}", ['name' => 'hr'])->assertOk()->assertJsonPath('department.name', 'hr');   // its own name in another case is fine
        $this->assertSame(2, Department::count());
    }

    public function test_renaming_changes_what_its_documents_show_and_is_logged(): void
    {
        $hr = Department::factory()->create(['name' => 'HR']);
        $id = $this->register('A', $hr->id);

        $this->api('PATCH', "/api/departments/{$hr->id}", ['name' => 'People and Culture'])->assertOk();

        $this->api('GET', "/api/documents/{$id}")->assertJsonPath('document.department', 'People and Culture')->assertJsonPath('document.departmentId', $hr->id);
        $changed = collect($this->api('GET', "/api/audits?table=departments&record={$hr->id}")->json('entries'))->firstWhere('change', 'Updated');
        $this->assertSame('HR', $changed['before']);
        $this->assertSame('People and Culture', $changed['after']);
    }

    public function test_a_switched_off_department_is_not_offered_or_accepted_for_new_documents_but_keeps_its_old_ones(): void
    {
        $hr = Department::factory()->create(['name' => 'HR']);
        $id = $this->register('A', $hr->id);

        $this->api('PATCH', "/api/departments/{$hr->id}", ['active' => false])->assertOk()->assertJsonPath('department.active', false);

        $this->assertSame([], $this->api('GET', '/api/documents')->json('departments'));
        $this->post('/api/documents', ['code' => 'B', 'title' => 'B', 'department_id' => $hr->id, 'files' => [UploadedFile::fake()->createWithContent('a.txt', 'x')]], self::FORM)
            ->assertStatus(422)->assertJsonPath('field', 'department_id');
        $this->api('GET', "/api/documents/{$id}")->assertJsonPath('document.department', 'HR');   // the old one is untouched
        $this->assertSame(['department.switched-off'], DB::table('audit_logs')->where('action', 'like', 'department.switched%')->pluck('action')->all());

        $this->api('PATCH', "/api/departments/{$hr->id}", ['active' => true])->assertOk();
        $this->assertCount(1, $this->api('GET', '/api/documents')->json('departments'));
        $this->assertContains('department.switched-on', DB::table('audit_logs')->pluck('action')->all());
    }

    public function test_a_document_may_have_no_department(): void
    {
        $id = $this->register('A', null);

        $this->api('GET', "/api/documents/{$id}")->assertJsonPath('document.department', '')->assertJsonPath('document.departmentId', null);
    }

    public function test_the_audit_log_shows_a_documents_department_by_name(): void
    {
        $hr = Department::factory()->create(['name' => 'HR']);
        $this->register('A', $hr->id);

        $row = collect($this->api('GET', '/api/audits?table=documents')->json('entries'))->firstWhere('column', 'department_id');

        $this->assertSame('Department', $row['columnLabel']);
        $this->assertSame('HR', $row['after']);
    }

    public function test_only_an_administrator_manages_departments(): void
    {
        User::factory()->editor()->create(['username' => 'edie']);
        User::factory()->viewer()->create(['username' => 'vic']);
        $hr = Department::factory()->create();

        foreach (['edie', 'vic'] as $name) {
            $this->as($name);
            $this->api('GET', '/api/departments')->assertStatus(403)->assertExactJson(['error' => 'forbidden']);
            $this->api('POST', '/api/departments', ['name' => 'Sneaky'])->assertStatus(403);
            $this->api('PATCH', "/api/departments/{$hr->id}", ['name' => 'Sneaky'])->assertStatus(403);
            $this->api('PATCH', '/api/departments/999', ['name' => 'x'])->assertStatus(403);   // not a 404
            $this->api('GET', '/api/documents')->assertOk();   // but the form's list of departments is for everyone who sees documents
        }
        $this->assertSame(1, Department::count());
    }
}
