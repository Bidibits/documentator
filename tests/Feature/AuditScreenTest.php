<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** GET /api/audits: the changes in audit_changes, read back with names. */
class AuditScreenTest extends TestCase
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

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->create($name, 5, 'application/pdf');
    }

    /** Ana registers LEASE-1; Ben adds Ver 1 to it. */
    private function twoSaves(): int
    {
        $this->as('ana');
        $id = $this->post('/api/documents', ['code' => 'LEASE-1', 'title' => 'Office lease', 'files' => [$this->pdf('lease.pdf')]],
            ['X-Documentator' => '1', 'Accept' => 'application/json'])->assertCreated()->json('document.id');
        $this->as('ben');
        $this->post("/api/documents/{$id}/versions", ['change_note' => 'Rent', 'files' => [$this->pdf('v2.pdf')]],
            ['X-Documentator' => '1', 'Accept' => 'application/json'])->assertCreated();

        return $id;
    }

    public function test_it_needs_a_sign_in(): void
    {
        $this->api('GET', '/api/audits')->assertStatus(401)->assertExactJson(['error' => 'signin']);
    }

    public function test_changes_come_newest_first_with_names_instead_of_ids(): void
    {
        $this->twoSaves();

        $entries = collect($this->api('GET', '/api/audits')->assertOk()->json('entries'));

        // the newest save first (Ben's change to the document), its columns in the order they were written
        $this->assertSame(['current_version_id', 'updated_by'], $entries->take(2)->pluck('column')->all());
        $this->assertSame(['Ben Cruz', 'Ben Cruz'], $entries->take(2)->pluck('by')->all());
        $this->assertSame($entries->take(2)->first()['saveId'], $entries->take(2)->last()['saveId']);
        $versionSave = $entries->where('table', 'document_versions')->where('after', '!=', null)->take(4);
        $this->assertSame(['document_id', 'rev_no', 'change_note', 'created_by'], $versionSave->pluck('column')->all());

        $current = $entries->first(fn ($e) => $e['column'] === 'current_version_id' && $e['change'] === 'Updated');
        $this->assertSame('LEASE-1 · Ver 0', $current['before']);
        $this->assertSame('LEASE-1 · Ver 1', $current['after']);
        $this->assertSame('Documents', $current['tableLabel']);
        $this->assertSame('Current version', $current['columnLabel']);
        $this->assertSame('LEASE-1', $current['record']);
        $this->assertSame('Ben Cruz', $current['by']);
        $this->assertSame('Manual', $current['source']);

        $createdBy = $entries->first(fn ($e) => $e['column'] === 'created_by' && $e['table'] === 'documents');
        $this->assertSame('Ana Reyes', $createdBy['after']);

        $version = $entries->first(fn ($e) => $e['table'] === 'document_versions' && $e['column'] === 'rev_no' && $e['after'] === '1');
        $this->assertSame('LEASE-1 · Ver 1', $version['record']);

        $file = $entries->first(fn ($e) => $e['table'] === 'document_files' && $e['column'] === 'original_name' && $e['after'] === 'v2.pdf');
        $this->assertSame('v2.pdf', $file['record']);
        $this->assertSame('Files', $file['tableLabel']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT[\d:.]+Z$/', $file['at']);
    }

    public function test_a_record_that_is_gone_shows_no_name_and_keeps_its_id(): void
    {
        $id = $this->twoSaves();
        DB::table('documents')->where('id', $id)->delete();   // cascades; the log rows stay

        $entry = collect($this->api('GET', '/api/audits')->json('entries'))->first(fn ($e) => $e['table'] === 'documents' && $e['column'] === 'code');
        $this->assertNull($entry['record']);
        $this->assertSame((string) $id, $entry['recordId']);
        $this->assertSame('LEASE-1', $entry['after']);
    }

    public function test_it_narrows_by_table_record_and_person(): void
    {
        $id = $this->twoSaves();

        $tables = $this->api('GET', '/api/audits?table=document_versions')->json('entries');
        $this->assertSame(['document_versions'], array_values(array_unique(array_column($tables, 'table'))));

        $one = $this->api('GET', "/api/audits?table=documents&record={$id}")->json('entries');
        $this->assertNotEmpty($one);
        $this->assertSame([(string) $id], array_values(array_unique(array_column($one, 'recordId'))));

        $ben = $this->api('GET', '/api/audits?person='.User::findByUsername('ben')->id)->json('entries');
        $this->assertSame(['Ben Cruz'], array_values(array_unique(array_column($ben, 'by'))));

        $this->assertSame([], $this->api('GET', '/api/audits?table=documents&record=9999')->json('entries'));
    }

    public function test_it_narrows_by_days_on_the_offices_clock(): void
    {
        $this->as('ana');
        $row = fn (string $at, string $code) => [
            'save_id' => 's'.$code, 'occurred_at' => $at, 'actor_user_id' => 1, 'actor_username' => 'ana', 'source' => 'Manual',
            'table_name' => 'documents', 'record_id' => '1', 'column_name' => 'code', 'value_before' => null, 'value_after' => $code,
            'change_type' => 'Created', 'ip' => null,
        ];
        // Manila is UTC+8: 2026-11-04 17:00 UTC is already 5 Nov at 01:00 there
        DB::table('audit_changes')->insert([
            $row('2026-11-04 15:59:59', 'A'),   // 4 Nov 23:59:59 Manila
            $row('2026-11-04 16:00:00', 'B'),   // 5 Nov 00:00:00 Manila
            $row('2026-11-05 15:59:59', 'C'),   // 5 Nov 23:59:59 Manila
            $row('2026-11-05 16:00:00', 'D'),   // 6 Nov 00:00:00 Manila
        ]);

        $on5th = $this->api('GET', '/api/audits?from=2026-11-05&to=2026-11-05')->assertOk()->json('entries');
        $this->assertEqualsCanonicalizing(['B', 'C'], array_column($on5th, 'after'));

        $from5 = $this->api('GET', '/api/audits?from=2026-11-05')->json('entries');
        $this->assertEqualsCanonicalizing(['B', 'C', 'D'], array_column($from5, 'after'));
    }

    public function test_odd_filters_are_refused_naming_the_field(): void
    {
        $this->as('ana');

        $this->api('GET', '/api/audits?table=passwords')->assertStatus(422)->assertJsonPath('field', 'table');
        $this->api('GET', '/api/audits?record=abc')->assertStatus(422)->assertJsonPath('field', 'record');
        $this->api('GET', '/api/audits?person=x')->assertStatus(422)->assertJsonPath('field', 'person');
        $this->api('GET', '/api/audits?from=2026-13-40')->assertStatus(422)->assertJsonPath('field', 'from');
        $this->api('GET', '/api/audits?from=2026-11-06&to=2026-11-01')->assertStatus(422)->assertJsonPath('field', 'to');
    }

    public function test_only_the_newest_500_come_and_it_says_there_are_more(): void
    {
        $this->as('ana');
        $rows = [];
        for ($i = 1; $i <= 501; $i++) {
            $rows[] = ['save_id' => "s{$i}", 'occurred_at' => '2026-11-05 00:00:00', 'actor_user_id' => 1, 'actor_username' => 'ana', 'source' => 'Manual',
                'table_name' => 'documents', 'record_id' => '1', 'column_name' => 'title', 'value_before' => null, 'value_after' => "T{$i}",
                'change_type' => 'Created', 'ip' => null];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('audit_changes')->insert($chunk);
        }

        $response = $this->api('GET', '/api/audits')->assertOk();
        $this->assertCount(500, $response->json('entries'));
        $this->assertTrue($response->json('more'));
        $this->assertSame('T501', $response->json('entries.0.after'));
    }

    public function test_it_lists_the_people_who_made_changes_and_the_tables_it_can_filter_by(): void
    {
        $this->twoSaves();

        $response = $this->api('GET', '/api/audits')->assertOk();
        $this->assertEqualsCanonicalizing(['Ana Reyes', 'Ben Cruz'], array_column($response->json('people'), 'name'));
        $this->assertSame(['documents', 'document_versions', 'document_files', 'users', 'archive_rules', 'departments'], array_column($response->json('tables'), 'table'));
    }

    public function test_passwords_show_as_hidden_and_yes_no_in_words(): void
    {
        $this->artisan('documentator:set-password', ['username' => 'cara', '--create' => true, '--name' => 'Cara Lim'])
            ->expectsQuestion('Password', 'long-enough-1')->expectsQuestion('Password again', 'long-enough-1')->assertSuccessful();
        $this->as('ana');

        $entries = collect($this->api('GET', '/api/audits?table=users')->json('entries'));
        $this->assertSame('(hidden)', $entries->first(fn ($e) => $e['column'] === 'password')['after']);
        $this->assertSame('Yes', $entries->first(fn ($e) => $e['column'] === 'active')['after']);
        $this->assertSame('Cara Lim', $entries->first(fn ($e) => $e['column'] === 'username')['record']);
        $this->assertSame('The command line', $entries->first()['by']);
        $this->assertStringNotContainsString('long-enough', json_encode($entries));
    }
}
