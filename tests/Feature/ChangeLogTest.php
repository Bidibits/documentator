<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DocumentFile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** What gets written to audit_changes, and the last viewed / downloaded dates. */
class ChangeLogTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        User::factory()->create(['username' => 'ana', 'name' => 'Ana Reyes']);
        User::factory()->create(['username' => 'ben', 'name' => 'Ben Cruz']);
    }

    private function as(string $username): void
    {
        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie(config('session.cookie'), '');
        $this->signIn($username)->assertOk();
    }

    private function register(string $code = 'LEASE-1', string $title = 'Office lease'): TestResponse
    {
        return $this->post('/api/documents', [
            'code' => $code, 'title' => $title, 'department_id' => Department::firstOrCreate(['name' => 'HR'])->id, 'issued_on' => '2026-11-01',
            'files' => [UploadedFile::fake()->create('lease.pdf', 20, 'application/pdf')],
        ], ['X-Documentator' => '1', 'Accept' => 'application/json']);
    }

    private function changes(string $table): array
    {
        return DB::table('audit_changes')->where('table_name', $table)->orderBy('id')->get()
            ->map(fn ($r) => "{$r->column_name}: ".($r->value_before ?? '-').' > '.($r->value_after ?? '-')." ({$r->change_type})")->all();
    }

    public function test_registering_a_document_logs_one_row_per_column_for_the_document_its_version_and_its_file(): void
    {
        $this->as('ana');
        $id = $this->register()->assertCreated()->json('document.id');

        $document = $this->changes('documents');
        $this->assertContains('code: - > LEASE-1 (Created)', $document);
        $this->assertContains('title: - > Office lease (Created)', $document);
        $this->assertContains('department_id: - > 1 (Created)', $document);
        $this->assertContains('issued_on: - > 2026-11-01 (Created)', $document);
        $this->assertNotContains('expires_on', array_map(fn ($c) => explode(':', $c)[0], $document));
        $this->assertContains('current_version_id: - > 1 (Created)', $document);

        $this->assertSame(['document_id: - > 1 (Created)', 'rev_no: - > 0 (Created)', 'issued_on: - > 2026-11-01 (Created)', 'created_by: - > 1 (Created)'], $this->changes('document_versions'));
        $this->assertContains('original_name: - > lease.pdf (Created)', $this->changes('document_files'));
        $this->assertContains('mime: - > application/pdf (Created)', $this->changes('document_files'));

        $row = DB::table('audit_changes')->where('table_name', 'documents')->first();
        $this->assertSame((string) $id, $row->record_id);
        $this->assertSame('ana', $row->actor_username);
        $this->assertSame('Manual', $row->source);
        $this->assertNotNull($row->ip);
        $this->assertSame(1, DB::table('audit_changes')->where('table_name', 'documents')->distinct()->count('save_id'));
    }

    public function test_a_new_version_logs_the_version_and_the_documents_changed_columns_only(): void
    {
        $this->as('ana');
        $id = $this->register()->assertCreated()->json('document.id');
        $this->as('ben');
        $this->post("/api/documents/{$id}/versions", ['change_note' => 'Rent raised', 'files' => [UploadedFile::fake()->create('v2.pdf', 5, 'application/pdf')]],
            ['X-Documentator' => '1', 'Accept' => 'application/json'])->assertCreated();

        $this->assertSame([
            'document_id: - > 1 (Created)', 'rev_no: - > 0 (Created)', 'issued_on: - > 2026-11-01 (Created)', 'created_by: - > 1 (Created)',
            'document_id: - > 1 (Created)', 'rev_no: - > 1 (Created)', 'change_note: - > Rent raised (Created)', 'issued_on: - > 2026-11-01 (Created)', 'created_by: - > 2 (Created)',
        ], $this->changes('document_versions'));

        $updates = DB::table('audit_changes')->where('table_name', 'documents')->where('change_type', 'Updated')->orderBy('id')->get();
        $this->assertSame(['current_version_id', 'updated_by'], $updates->pluck('column_name')->all());
        $this->assertSame(['1', '1'], $updates->pluck('value_before')->all());
        $this->assertSame(['2', '2'], $updates->pluck('value_after')->all());
        $this->assertSame(['ben', 'ben'], $updates->pluck('actor_username')->all());
    }

    public function test_a_refused_save_leaves_no_change_rows(): void
    {
        $this->as('ana');
        $this->register()->assertCreated();
        $before = DB::table('audit_changes')->count();

        $this->register('lease-1')->assertStatus(409);

        $this->assertSame($before, DB::table('audit_changes')->count());
    }

    public function test_the_change_rows_cannot_be_edited_or_removed(): void
    {
        $this->as('ana');
        $this->register()->assertCreated();

        $this->expectException(QueryException::class);
        DB::table('audit_changes')->update(['value_after' => 'forged']);
    }

    public function test_the_change_rows_cannot_be_deleted(): void
    {
        $this->as('ana');
        $this->register()->assertCreated();

        $this->expectException(QueryException::class);
        DB::table('audit_changes')->delete();
    }

    public function test_making_an_account_and_setting_its_password_are_logged_with_the_password_hidden(): void
    {
        $this->artisan('documentator:set-password', ['username' => 'cara', '--create' => true, '--name' => 'Cara Lim'])
            ->expectsQuestion('Password', 'long-enough-1')->expectsQuestion('Password again', 'long-enough-1')->assertSuccessful();

        $created = DB::table('audit_changes')->where('table_name', 'users')->pluck('value_after', 'column_name')->all();
        $this->assertSame('cara', $created['username']);
        $this->assertSame('(hidden)', $created['password']);
        $this->assertSame('true', $created['active']);
        $this->assertStringNotContainsString('long-enough', json_encode(DB::table('audit_changes')->get()));

        $this->travel(1)->minutes();   // the date of the password is kept to the second, so a change needs a later one
        $this->artisan('documentator:set-password', ['username' => 'cara'])
            ->expectsQuestion('Password', 'another-long-2')->expectsQuestion('Password again', 'another-long-2')->assertSuccessful();

        $updated = DB::table('audit_changes')->where('table_name', 'users')->where('change_type', 'Updated')->pluck('column_name')->all();
        $this->assertContains('password', $updated);
        $this->assertContains('password_changed_at', $updated);
        $this->assertNotContains('username', $updated);
    }

    // ---- last viewed / last downloaded ----

    public function test_a_file_remembers_when_and_by_whom_it_was_last_viewed_and_downloaded(): void
    {
        $this->as('ana');
        $id = $this->register()->assertCreated()->json('document.id');
        $file = DocumentFile::firstOrFail();

        $this->api('GET', "/api/documents/{$id}")->assertJsonPath('document.versions.0.files.0.lastViewedAt', '')
            ->assertJsonPath('document.versions.0.files.0.lastDownloadedBy', '');

        $this->api('GET', "/api/files/{$file->id}?view=1")->assertOk();
        $this->as('ben');
        $this->api('GET', "/api/files/{$file->id}")->assertOk();

        $shown = $this->api('GET', "/api/documents/{$id}")->assertOk()->json('document.versions.0.files.0');
        $this->assertSame('Ana Reyes', $shown['lastViewedBy']);
        $this->assertSame('Ben Cruz', $shown['lastDownloadedBy']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT[\d:.]+Z$/', $shown['lastViewedAt']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT[\d:.]+Z$/', $shown['lastDownloadedAt']);

        // pressing View again moves only the view date
        $downloaded = $shown['lastDownloadedAt'];
        $this->as('ben');
        $this->api('GET', "/api/files/{$file->id}?view=1")->assertOk();
        $again = $this->api('GET', "/api/documents/{$id}")->json('document.versions.0.files.0');
        $this->assertSame('Ben Cruz', $again['lastViewedBy']);
        $this->assertSame($downloaded, $again['lastDownloadedAt']);

        // the use itself is not column-audited (audit_logs has each press)
        $this->assertSame(0, DB::table('audit_changes')->whereIn('column_name', ['last_viewed_at', 'last_downloaded_at'])->count());
    }

    public function test_the_list_shows_the_latest_view_and_download_of_any_file_of_any_version(): void
    {
        $this->as('ana');
        $id = $this->register()->assertCreated()->json('document.id');
        $this->register('OTHER-1', 'Other')->assertCreated();

        $this->api('GET', '/api/documents')->assertJsonPath('documents.0.lastViewedAt', '')->assertJsonPath('documents.1.lastDownloadedAt', '');

        $this->api('GET', '/api/files/'.DocumentFile::firstOrFail()->id.'?view=1')->assertOk();

        $list = $this->api('GET', '/api/documents')->assertOk()->json('documents');
        $byId = collect($list)->keyBy('id');
        $this->assertNotSame('', $byId[$id]['lastViewedAt']);
        $this->assertSame('', $byId[$id]['lastDownloadedAt']);
        $this->assertSame('', $byId[$id + 1]['lastViewedAt']);
    }

    public function test_a_missing_file_is_not_marked_as_used(): void
    {
        $this->as('ana');
        $this->register()->assertCreated();
        $file = DocumentFile::firstOrFail();
        Storage::disk('local')->delete($file->storage_path);

        $this->api('GET', "/api/files/{$file->id}?view=1")->assertStatus(404);

        $this->assertNull($file->fresh()->last_viewed_at);
    }
}
