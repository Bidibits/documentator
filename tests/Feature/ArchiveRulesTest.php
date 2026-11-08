<?php

namespace Tests\Feature;

use App\Models\DocumentFile;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** The rules that archive old versions by themselves, and the nightly run. */
class ArchiveRulesTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private const FORM = ['X-Documentator' => '1', 'Accept' => 'application/json'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(now()->startOfDay()->addHours(10));
        User::factory()->create(['username' => 'ana', 'name' => 'Ana Reyes']);
        $this->signIn('ana')->assertOk();
    }

    /** Jumps ahead in time and signs in again (a session does not last that long). */
    private function later(int $days): void
    {
        $this->travel($days)->days();
        $this->app['auth']->forgetGuards();
        $this->signIn('ana')->assertOk();
    }

    private function text(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /** A document with Ver 0 and Ver 1 uploaded now (so Ver 0 is old). Returns [document id, Ver 0 id, Ver 1 id]. */
    private function document(string $code = 'LEASE-1'): array
    {
        $id = $this->post('/api/documents', ['code' => $code, 'title' => 'Lease', 'files' => [$this->text('a.txt', 'first')]], self::FORM)->assertCreated()->json('document.id');
        $this->post("/api/documents/{$id}/versions", ['files' => [$this->text('b.txt', 'second')]], self::FORM)->assertCreated();

        return [$id, $this->versionId($id, 0), $this->versionId($id, 1)];
    }

    private function versionId(int $document, int $rev): int
    {
        return (int) DocumentVersion::where('document_id', $document)->where('rev_no', $rev)->value('id');
    }

    private function rule(string $kind, bool $enabled, int $amount, string $unit): TestResponse
    {
        return $this->api('PUT', "/api/archive/rules/{$kind}", ['enabled' => $enabled, 'amount' => $amount, 'unit' => $unit]);
    }

    private function archived(int $version): bool
    {
        return DocumentVersion::findOrFail($version)->archived_at !== null;
    }

    private function runNow(): array
    {
        return $this->api('POST', '/api/archive/run')->assertOk()->json();
    }

    // ---- the rules themselves ----

    public function test_both_rules_start_off_with_nothing_due(): void
    {
        $this->document();

        $response = $this->api('GET', '/api/archive/rules')->assertOk();
        $this->assertSame([['kind' => 'idle', 'enabled' => false, 'amount' => 1, 'unit' => 'years', 'due' => 0], ['kind' => 'old', 'enabled' => false, 'amount' => 0, 'unit' => 'days', 'due' => 0]], $response->json('rules'));
        $this->assertSame(0, $response->json('dueTotal'));
        $this->assertNull($response->json('lastRun'));
        $this->assertStringContainsString('02:00', $response->json('schedule'));
    }

    public function test_a_rule_is_saved_and_every_changed_column_is_logged(): void
    {
        $this->rule('idle', true, 6, 'months')->assertOk()->assertJsonPath('rules.0.enabled', true)->assertJsonPath('rules.0.amount', 6)->assertJsonPath('rules.0.unit', 'months');
        $this->rule('idle', true, 2, 'years')->assertOk();

        $rows = DB::table('audit_changes')->where('table_name', 'archive_rules')->orderBy('id')->get()
            ->map(fn ($r) => "{$r->column_name}: ".($r->value_before ?? '-').' > '.($r->value_after ?? '-')." ({$r->change_type})")->all();
        $this->assertSame(['kind: - > idle (Created)', 'enabled: - > true (Created)', 'amount: - > 6 (Created)', 'unit: - > months (Created)',
            'amount: 6 > 2 (Updated)', 'unit: months > years (Updated)'], $rows);

        $shown = collect($this->api('GET', '/api/audits?table=archive_rules')->json('entries'));
        $this->assertSame('Not opened for a while', $shown->first()['record']);
        $this->assertSame('Archive rules', $shown->first()['tableLabel']);
        $this->assertSame('Yes', $shown->firstWhere('column', 'enabled')['after']);
    }

    public function test_odd_rules_are_refused(): void
    {
        $this->rule('idle', true, 0, 'days')->assertStatus(422)->assertJsonPath('error', 'invalid');   // "never opened for 0 days" would archive everything
        $this->rule('idle', true, 1000, 'days')->assertStatus(422);
        $this->rule('idle', true, 3, 'weeks')->assertStatus(422)->assertJsonPath('field', 'unit');
        $this->rule('old', true, -1, 'days')->assertStatus(422);
        $this->rule('old', true, 0, 'days')->assertOk();   // as soon as it is replaced
        $this->api('PUT', '/api/archive/rules/other', ['enabled' => true, 'amount' => 1, 'unit' => 'days'])->assertStatus(404);
        $this->api('PUT', '/api/archive/rules/idle', ['amount' => 1, 'unit' => 'days'])->assertStatus(422);
    }

    // ---- what is due ----

    public function test_the_idle_rule_archives_old_versions_not_opened_for_the_period_and_never_a_current_one(): void
    {
        [, $v0, $v1] = $this->document();
        $this->rule('idle', true, 30, 'days')->assertOk();

        $this->assertSame(0, $this->runNow()['archived']);   // just uploaded: not idle yet
        $this->later(31);

        $this->assertSame(1, $this->api('GET', '/api/archive/rules')->json('rules.0.due'));
        $result = $this->runNow();

        $this->assertSame(['archived' => 1, 'failed' => [], 'due' => 1], $result);
        $this->assertTrue($this->archived($v0));
        $this->assertFalse($this->archived($v1));   // current: left alone, however long it has sat there
    }

    public function test_opening_a_file_starts_its_period_again(): void
    {
        [, $v0] = $this->document();
        $this->rule('idle', true, 30, 'days')->assertOk();
        $this->later(25);
        $this->api('GET', '/api/files/'.DocumentFile::where('version_id', $v0)->value('id').'?view=1')->assertOk();

        $this->later(15);   // 40 days since upload, 15 since it was viewed
        $this->assertSame(0, $this->runNow()['archived']);
        $this->assertFalse($this->archived($v0));

        $this->later(16);   // 31 days since it was viewed
        $this->assertSame(1, $this->runNow()['archived']);
        $this->assertTrue($this->archived($v0));
    }

    public function test_months_and_years_count_as_calendar_months_and_years(): void
    {
        [, $v0] = $this->document();
        $this->rule('idle', true, 2, 'months')->assertOk();
        $this->later(59);   // not yet two calendar months at most times of year
        $due = $this->api('GET', '/api/archive/rules')->json('rules.0.due');
        $this->later(5);
        $this->assertSame(1, $this->api('GET', '/api/archive/rules')->json('rules.0.due'));
        $this->assertContains($due, [0, 1]);

        $this->rule('idle', true, 1, 'years')->assertOk();
        $this->assertSame(0, $this->api('GET', '/api/archive/rules')->json('rules.0.due'));
        $this->later(366);
        $this->assertSame(1, $this->api('GET', '/api/archive/rules')->json('rules.0.due'));
        $this->assertFalse($this->archived($v0));   // asking what is due changes nothing
    }

    public function test_the_old_rule_archives_a_version_the_given_time_after_a_newer_one_replaced_it(): void
    {
        [$id, $v0, $v1] = $this->document();
        $this->rule('old', true, 7, 'days')->assertOk();

        $this->assertSame(0, $this->runNow()['archived']);
        $this->later(8);
        $this->assertSame(1, $this->runNow()['archived']);
        $this->assertTrue($this->archived($v0));
        $this->assertFalse($this->archived($v1));

        // Ver 2 arrives: Ver 1 becomes old only now, so its week starts now
        $this->post("/api/documents/{$id}/versions", ['files' => [$this->text('c.txt', 'third')]], self::FORM)->assertCreated();
        $this->assertSame(0, $this->runNow()['archived']);
        $this->later(8);
        $this->assertSame(1, $this->runNow()['archived']);
        $this->assertTrue($this->archived($v1));
    }

    public function test_the_old_rule_with_zero_days_archives_as_soon_as_a_version_is_replaced(): void
    {
        [$id, $v0, $v1] = $this->document();
        $this->rule('old', true, 0, 'days')->assertOk();

        $this->runNow();

        $this->assertTrue($this->archived($v0));
        $this->assertFalse($this->archived($v1));   // still the current version
        $this->assertSame(0, DocumentVersion::where('document_id', $id)->where('rev_no', 2)->count());
    }

    public function test_a_version_matching_both_rules_is_archived_once(): void
    {
        [, $v0] = $this->document();
        $this->rule('idle', true, 1, 'days')->assertOk();
        $this->rule('old', true, 0, 'days')->assertOk();
        $this->later(2);

        $rules = $this->api('GET', '/api/archive/rules');
        $this->assertSame([1, 1], array_column($rules->json('rules'), 'due'));
        $this->assertSame(1, $rules->json('dueTotal'));
        $this->assertSame(1, $this->runNow()['archived']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'version.archived')->count());
        $this->assertTrue($this->archived($v0));
    }

    public function test_a_rule_that_is_off_does_nothing(): void
    {
        [, $v0] = $this->document();
        $this->rule('old', false, 0, 'days')->assertOk();
        $this->rule('idle', false, 1, 'days')->assertOk();
        $this->later(400);

        $this->assertSame(['archived' => 0, 'failed' => [], 'due' => 0], $this->runNow());
        $this->assertFalse($this->archived($v0));
    }

    public function test_a_version_taken_out_of_the_archive_is_not_archived_again_the_same_night(): void
    {
        [, $v0] = $this->document();
        $this->rule('old', true, 0, 'days')->assertOk();
        $this->rule('idle', true, 30, 'days')->assertOk();
        $this->later(40);
        $this->runNow();
        $this->assertTrue($this->archived($v0));

        $this->api('POST', "/api/versions/{$v0}/restore")->assertOk();
        $this->assertNotNull(DocumentVersion::findOrFail($v0)->restored_at);
        $this->assertSame(0, $this->runNow()['archived']);   // "old" leaves it alone for good; "idle" counts from the restore
        $this->assertFalse($this->archived($v0));

        $this->later(31);
        $this->assertSame(1, $this->runNow()['archived']);   // unopened for the period again
        $this->assertTrue($this->archived($v0));
    }

    // ---- how it runs ----

    public function test_an_automatic_archive_is_logged_as_the_system_and_the_run_is_noted(): void
    {
        [, $v0] = $this->document();
        $this->rule('old', true, 0, 'days')->assertOk();

        $this->artisan('documentator:archive-due')->expectsOutput('Archived 1 of 1 due.')->assertSuccessful();

        $version = DocumentVersion::findOrFail($v0);
        $this->assertNull($version->archived_by);
        $row = DB::table('audit_changes')->where('table_name', 'document_versions')->where('column_name', 'archived_at')->first();
        $this->assertSame('Automatic', $row->source);
        $this->assertSame('system', $row->actor_username);
        $this->assertNull($row->actor_user_id);

        $log = DB::table('audit_logs')->where('action', 'version.archived')->first();
        $this->assertSame('system', $log->actor_username);
        $this->assertStringContainsString('archive rule', $log->note);

        $last = $this->api('GET', '/api/archive/rules')->json('lastRun');
        $this->assertSame('the nightly run', $last['by']);
        $this->assertStringContainsString('archived 1', $last['note']);

        $shown = collect($this->api('GET', '/api/audits?table=document_versions')->json('entries'))->firstWhere('column', 'archived_at');
        $this->assertSame('The system (archive rules)', $shown['by']);
        $this->assertSame('Automatic', $shown['source']);

        $list = $this->api('GET', '/api/archive')->json('versions');
        $this->assertSame('an archive rule', $list[0]['archivedBy']);
    }

    public function test_the_command_can_say_what_is_due_without_doing_it(): void
    {
        [, $v0] = $this->document();
        $this->rule('old', true, 0, 'days')->assertOk();

        $this->artisan('documentator:archive-due', ['--dry-run' => true])
            ->expectsOutput('Due now: 1 (not opened for a while: 0, replaced by a newer version: 1)')->assertSuccessful();

        $this->assertFalse($this->archived($v0));
    }

    public function test_a_version_that_cannot_be_archived_is_reported_and_the_run_carries_on(): void
    {
        [, $broken] = $this->document('BROKEN-1');
        [, $fine] = $this->document('FINE-1');
        $this->rule('old', true, 0, 'days')->assertOk();
        Storage::disk('local')->delete(DocumentFile::where('version_id', $broken)->value('storage_path'));

        $result = $this->runNow();

        $this->assertSame(1, $result['archived']);
        $this->assertSame(2, $result['due']);
        $this->assertSame([['id' => $broken, 'code' => 'BROKEN-1', 'rev' => 0, 'error' => 'file_missing']], $result['failed']);
        $this->assertFalse($this->archived($broken));
        $this->assertTrue($this->archived($fine));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'version.archive-failed')->count());
    }

    public function test_a_run_stops_at_its_limit_and_says_how_many_are_left(): void
    {
        $this->document('A-1');
        $this->document('B-1');
        $this->document('C-1');
        $this->rule('old', true, 0, 'days')->assertOk();

        $this->artisan('documentator:archive-due', ['--limit' => 2])->expectsOutput('Archived 2 of 3 due.')->assertSuccessful();

        $this->assertStringContainsString('1 left for the next run', DB::table('audit_logs')->where('action', 'archive.run')->value('note'));
        $this->artisan('documentator:archive-due')->expectsOutput('Archived 1 of 1 due.')->assertSuccessful();
    }

    public function test_the_command_is_scheduled_every_night_at_two_by_the_office_clock(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'documentator:archive-due'));

        $this->assertNotNull($event);
        $this->assertSame(config('documentator.timezone'), $event->timezone);
        $this->assertSame('0 2 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
