<?php

namespace Tests\Feature;

use App\Auth\Access;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\DocumentVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** Pages, search and filters on the lists, and deleting a document for good. */
class PagingAndDeleteTest extends TestCase
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

    private function day(int $offset): string
    {
        return Carbon::today(config('documentator.timezone'))->addDays($offset)->format('Y-m-d');
    }

    private function register(array $fields = [], string $name = 'permit.pdf'): int
    {
        $fields += ['code' => 'TSI-1', 'title' => 'Business permit', 'files' => [UploadedFile::fake()->create($name, 10, 'application/pdf')]];

        return $this->post('/api/documents', $fields, self::FORM)->assertCreated()->json('document.id');
    }

    private function codes(string $uri): array
    {
        return array_column($this->api('GET', $uri)->assertOk()->json('documents'), 'code');
    }

    // ---- the Documents list ----

    public function test_the_register_comes_twenty_five_to_a_page_newest_first(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            Document::factory()->create(['code' => sprintf('D-%02d', $i), 'title' => 'Doc '.$i]);
        }

        $first = $this->api('GET', '/api/documents')->assertOk();
        $this->assertCount(25, $first->json('documents'));
        $this->assertSame([1, 3, 60, 25], [$first->json('page'), $first->json('pages'), $first->json('total'), $first->json('per')]);
        $this->assertSame('D-60', $first->json('documents.0.code'));
        $this->assertSame('D-36', $first->json('documents.24.code'));

        $last = $this->api('GET', '/api/documents?page=3')->assertOk();
        $this->assertCount(10, $last->json('documents'));
        $this->assertSame('D-10', $last->json('documents.0.code'));

        $this->api('GET', '/api/documents?page=99')->assertOk()->assertJsonPath('page', 3);
        $this->assertCount(50, $this->api('GET', '/api/documents?per=50')->json('documents'));
        $this->api('GET', '/api/documents?per=7')->assertStatus(422);
        $this->api('GET', '/api/documents?page=0')->assertStatus(422);
    }

    public function test_an_empty_register_is_one_empty_page(): void
    {
        $this->api('GET', '/api/documents')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('pages', 1)->assertJsonPath('page', 1)->assertJsonPath('documents', []);
    }

    public function test_search_needs_every_word_in_the_code_title_type_location_or_department(): void
    {
        Document::factory()->create(['code' => 'A-1', 'title' => 'Office lease', 'doc_type' => 'Contract', 'location_note' => 'Cabinet 3']);
        Document::factory()->create(['code' => 'A-2', 'title' => 'Mayor permit', 'doc_type' => 'Permit', 'department_id' => $this->hr->id]);
        Document::factory()->create(['code' => 'A_3', 'title' => '100% done']);

        $this->assertSame(['A-1'], $this->codes('/api/documents?q=lease'));
        $this->assertSame(['A-1'], $this->codes('/api/documents?q=cabinet+LEASE'));
        $this->assertSame(['A-2'], $this->codes('/api/documents?q=permit+hr'));
        $this->assertSame([], $this->codes('/api/documents?q=lease+permit'));
        // % and _ are letters here, not wildcards
        $this->assertSame(['A_3'], $this->codes('/api/documents?q=a_3'));
        $this->assertSame(['A_3'], $this->codes('/api/documents?q='.urlencode('100%')));
        $this->assertSame([], $this->codes('/api/documents?q=%25'.urlencode('zzz')));
        $this->assertCount(3, $this->codes('/api/documents?q=a'));

        $this->api('GET', '/api/documents?q=lease')->assertJsonPath('total', 1)->assertJsonPath('pages', 1);
    }

    public function test_the_expiry_filter_and_the_count_follow_the_office_calendar(): void
    {
        Document::factory()->create(['code' => 'NONE']);
        Document::factory()->create(['code' => 'FAR', 'expires_on' => $this->day(31)]);
        Document::factory()->create(['code' => 'EDGE', 'expires_on' => $this->day(30)]);
        Document::factory()->create(['code' => 'TODAY', 'expires_on' => $this->day(0)]);
        Document::factory()->create(['code' => 'GONE', 'expires_on' => $this->day(-1)]);

        $this->assertEqualsCanonicalizing(['EDGE', 'TODAY', 'GONE'], $this->codes('/api/documents?status=attention'));
        $this->assertEqualsCanonicalizing(['EDGE', 'TODAY'], $this->codes('/api/documents?status=expiring'));
        $this->assertSame(['GONE'], $this->codes('/api/documents?status=expired'));
        $this->assertSame(['FAR'], $this->codes('/api/documents?status=active'));
        $this->assertSame(['NONE'], $this->codes('/api/documents?status=none'));
        $this->api('GET', '/api/documents?status=nonsense')->assertStatus(422);

        // the count does not depend on the filter or the page
        $this->api('GET', '/api/documents?status=none')->assertJsonPath('expiringCount', 3);
        // soonest first for the Expiring panel
        $this->assertSame(['GONE', 'TODAY', 'EDGE'], $this->codes('/api/documents?status=attention&sort=expiry'));
    }

    public function test_dates_stored_with_a_time_still_filter_on_the_day(): void
    {
        $document = Document::factory()->create(['code' => 'LATE']);
        DB::table('documents')->where('id', $document->id)->update(['expires_on' => $this->day(30).' 00:00:00']);

        $this->assertSame(['LATE'], $this->codes('/api/documents?status=expiring'));
    }

    public function test_a_person_is_paged_and_counted_only_over_what_they_may_view(): void
    {
        Document::factory()->create(['code' => 'HR-1', 'department_id' => $this->hr->id, 'expires_on' => $this->day(5)]);
        Document::factory()->create(['code' => 'ACC-1', 'department_id' => $this->acc->id, 'expires_on' => $this->day(5)]);
        $this->person('hana', [$this->hr->id => ['view']]);
        $this->as('hana');

        $response = $this->api('GET', '/api/documents')->assertOk();
        $this->assertSame(['HR-1'], array_column($response->json('documents'), 'code'));
        $this->assertSame([1, 1], [$response->json('total'), $response->json('expiringCount')]);
    }

    // ---- the Archiving list ----

    private function oldVersions(int $count): Document
    {
        $document = Document::factory()->create(['code' => 'AAA', 'title' => 'Lease']);
        $user = User::first();
        for ($rev = 0; $rev <= $count; $rev++) {
            $version = DocumentVersion::create(['document_id' => $document->id, 'rev_no' => $rev, 'created_by' => $user->id]);
            DB::table('document_versions')->where('id', $version->id)->update(['created_at' => Carbon::parse('2026-01-01')->addMinutes($rev)]);
            $document->update(['current_version_id' => $version->id]);   // the last one made stays the current version
        }

        return $document;
    }

    public function test_the_archive_list_comes_in_pages_with_totals_for_everything(): void
    {
        $this->oldVersions(60);   // Ver 0..59 are old, Ver 60 is current
        DocumentVersion::where('rev_no', '<', 5)->update(['archived_at' => now(), 'archive_path' => 'x.zip', 'archive_size' => 100]);

        $first = $this->api('GET', '/api/archive')->assertOk();
        $this->assertCount(50, $first->json('versions'));
        $this->assertSame([1, 2, 60, 50], [$first->json('page'), $first->json('pages'), $first->json('total'), $first->json('per')]);
        $this->assertSame([60, 5, 500], [$first->json('summary.versions'), $first->json('summary.archived'), $first->json('summary.archivedZipBytes')]);
        $this->assertSame(59, $first->json('versions.0.rev'));   // never opened: the newer version first

        $this->assertCount(10, $this->api('GET', '/api/archive?page=2')->json('versions'));
        $this->assertSame(5, $this->api('GET', '/api/archive?filter=archived')->json('total'));
        $this->assertSame(55, $this->api('GET', '/api/archive?filter=live')->json('total'));
        $this->assertSame(60, $this->api('GET', '/api/archive?per=25')->json('total'));
        // the totals stay the same whatever is filtered
        $this->assertSame(60, $this->api('GET', '/api/archive?filter=archived')->json('summary.versions'));
        $this->api('GET', '/api/archive?filter=what')->assertStatus(422);
    }

    public function test_archive_search_matches_words_including_ver_numbers(): void
    {
        $this->oldVersions(60);

        $revs = array_column($this->api('GET', '/api/archive?q=ver+7')->assertOk()->json('versions'), 'rev');
        sort($revs);
        $this->assertSame([7, 17, 27, 37, 47, 57], $revs);
        $this->assertSame(60, $this->api('GET', '/api/archive?q=lease')->json('total'));
        $this->assertSame(0, $this->api('GET', '/api/archive?q=nothing')->json('total'));
    }

    public function test_the_archive_list_puts_the_most_recently_opened_first_and_the_never_opened_last(): void
    {
        $document = $this->oldVersions(3);
        $versions = DocumentVersion::where('document_id', $document->id)->orderBy('rev_no')->get();
        foreach ([0 => '2026-05-01 10:00:00', 1 => '2026-06-01 10:00:00'] as $rev => $when) {
            DB::table('document_files')->insert(['version_id' => $versions[$rev]->id, 'original_name' => 'a.pdf', 'storage_path' => "p{$rev}", 'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('a', 64), 'last_viewed_at' => $when]);
        }

        $this->assertSame([1, 0, 2], array_column($this->api('GET', '/api/archive')->json('versions'), 'rev'));
    }

    // ---- deleting a document ----

    private function documentWithTwoVersions(array $fields = []): int
    {
        $id = $this->register($fields);
        $this->post("/api/documents/{$id}/versions", ['files' => [UploadedFile::fake()->create('v2.pdf', 5, 'application/pdf')]], self::FORM)->assertCreated();

        return $id;
    }

    public function test_deleting_removes_the_document_its_versions_and_every_file_including_zips(): void
    {
        $id = $this->documentWithTwoVersions(['code' => 'TSI-DEL', 'title' => 'Old lease']);
        $other = $this->register(['code' => 'TSI-KEEP']);
        $oldVersion = DocumentVersion::where('document_id', $id)->where('rev_no', 0)->first();
        $this->api('POST', "/api/versions/{$oldVersion->id}/archive")->assertOk();   // Ver 0 is packed into a zip
        $this->assertNotEmpty(Storage::disk('local')->allFiles());
        $before = Storage::disk('local')->allFiles();

        $this->api('DELETE', "/api/documents/{$id}", ['confirm' => 'tsi-del '])->assertOk()->assertExactJson(['ok' => true]);

        $this->assertNull(Document::find($id));
        $this->assertSame(0, DocumentVersion::where('document_id', $id)->count());
        $this->assertSame(0, DocumentFile::where('version_id', $oldVersion->id)->count());
        $this->assertNotNull(Document::find($other));
        // only the other document's file is left
        $left = Storage::disk('local')->allFiles();
        $this->assertCount(1, $left);
        $this->assertStringStartsWith("documents/{$other}/", $left[0]);
        $this->assertGreaterThan(1, count($before));
        $this->api('GET', "/api/documents/{$id}")->assertStatus(404);
        $this->assertContains('document.deleted', DB::table('audit_logs')->pluck('action')->all());
    }

    public function test_audits_keeps_what_was_deleted_and_names_the_gone_document_by_its_code(): void
    {
        $id = $this->documentWithTwoVersions(['code' => 'TSI-DEL', 'title' => 'Old lease', 'expires_on' => '2027-01-01']);
        $this->api('DELETE', "/api/documents/{$id}", ['confirm' => 'TSI-DEL'])->assertOk();

        $rows = DB::table('audit_changes')->where('change_type', 'Deleted')->get();
        $this->assertSame(['document_files', 'document_versions', 'documents'], $rows->pluck('table_name')->unique()->sort()->values()->all());
        $code = $rows->where('table_name', 'documents')->firstWhere('column_name', 'code');
        $this->assertSame(['TSI-DEL', null], [$code->value_before, $code->value_after]);
        $this->assertSame('2027-01-01', $rows->where('table_name', 'documents')->firstWhere('column_name', 'expires_on')->value_before);
        $this->assertSame(2, $rows->where('table_name', 'document_versions')->where('column_name', 'rev_no')->count());
        $this->assertSame(2, $rows->where('table_name', 'document_files')->where('column_name', 'original_name')->count());

        $entry = collect($this->api('GET', "/api/audits?table=documents&record={$id}")->assertOk()->json('entries'))->firstWhere('change', 'Deleted');
        $this->assertSame('TSI-DEL', $entry['record']);
    }

    public function test_the_code_must_be_typed_to_delete(): void
    {
        $id = $this->register(['code' => 'TSI-DEL']);

        $this->api('DELETE', "/api/documents/{$id}")->assertStatus(422)->assertJsonPath('field', 'confirm');
        $this->api('DELETE', "/api/documents/{$id}", ['confirm' => 'TSI-DE'])->assertStatus(422)->assertJsonPath('field', 'confirm');
        $this->api('DELETE', "/api/documents/{$id}", ['confirm' => ''])->assertStatus(422);

        $this->assertNotNull(Document::find($id));
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_deleting_needs_a_sign_in_and_an_existing_document(): void
    {
        $id = $this->register(['code' => 'TSI-DEL']);
        $this->api('POST', '/api/logout');
        $this->api('DELETE', "/api/documents/{$id}", ['confirm' => 'TSI-DEL'])->assertStatus(401);
        $this->assertNotNull(Document::find($id));

        $this->as('ana');
        $this->api('DELETE', '/api/documents/999', ['confirm' => 'x'])->assertStatus(404)->assertExactJson(['error' => 'route']);
    }

    public function test_delete_is_its_own_tag_per_department(): void
    {
        $hr = $this->register(['code' => 'HR-1', 'department_id' => $this->hr->id]);
        $acc = $this->register(['code' => 'ACC-1', 'department_id' => $this->acc->id]);
        $this->person('editor', [$this->hr->id => ['view', 'download', 'update'], $this->acc->id => ['view', 'delete']]);
        $this->person('boss', [$this->hr->id => ['view', 'delete']]);

        $this->as('editor');
        $this->api('GET', "/api/documents/{$hr}")->assertJsonPath('document.can.delete', false);
        $this->api('GET', "/api/documents/{$acc}")->assertJsonPath('document.can.delete', true);
        $this->api('DELETE', "/api/documents/{$hr}", ['confirm' => 'HR-1'])->assertStatus(403);   // may update there, not delete
        $this->assertNotNull(Document::find($hr));

    }

    public function test_a_person_with_the_tag_deletes_in_their_department_only(): void
    {
        $hr = $this->register(['code' => 'HR-1', 'department_id' => $this->hr->id]);
        $acc = $this->register(['code' => 'ACC-1', 'department_id' => $this->acc->id]);
        $this->person('boss', [$this->hr->id => ['view', 'delete']]);
        $this->as('boss');

        $this->api('DELETE', "/api/documents/{$acc}", ['confirm' => 'ACC-1'])->assertStatus(403);
        $this->assertNotNull(Document::find($acc));
        $this->api('DELETE', "/api/documents/{$hr}", ['confirm' => 'HR-1'])->assertOk();
        $this->assertNull(Document::find($hr));
    }

    public function test_a_role_without_any_delete_tag_never_reaches_the_route(): void
    {
        $id = $this->register(['code' => 'TSI-1', 'department_id' => $this->hr->id]);
        $this->person('clerk', [$this->hr->id => ['view', 'download', 'register', 'update', 'archive']]);
        $this->as('clerk');

        $this->api('DELETE', "/api/documents/{$id}", ['confirm' => 'TSI-1'])->assertStatus(403)->assertExactJson(['error' => 'forbidden']);
        $this->assertNotNull(Document::find($id));
    }
}
