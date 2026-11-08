<?php

namespace Tests\Feature;

use App\Auth\Access;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** Correcting a document's details, renewing a document with new dates, and the expiry status the server works out. */
class ExpiryAndDetailsTest extends TestCase
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

    private function pdf(string $name = 'permit.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 10, 'application/pdf');
    }

    private function register(array $fields = []): int
    {
        $fields += ['code' => 'TSI-PERMIT-1', 'title' => 'Business permit', 'files' => [$this->pdf()]];

        return $this->post('/api/documents', $fields, self::FORM)->assertCreated()->json('document.id');
    }

    private function renew(int $id, array $fields): TestResponse
    {
        return $this->post("/api/documents/{$id}/versions", $fields + ['files' => [$this->pdf('permit-2027.pdf')]], self::FORM);
    }

    private function day(int $offset): string
    {
        return Carbon::today(config('documentator.timezone'))->addDays($offset)->format('Y-m-d');
    }

    private function changes(string $table, int $id): array
    {
        return DB::table('audit_changes')->where('table_name', $table)->where('record_id', (string) $id)->orderBy('id')
            ->get(['column_name', 'value_before', 'value_after'])->map(fn ($r) => (array) $r)->all();
    }

    // ---- editing the details ----

    public function test_the_details_can_be_corrected_and_the_code_stays(): void
    {
        $id = $this->register(['issued_on' => '2025-01-01', 'expires_on' => '2025-12-31', 'doc_type' => 'Permit']);

        $this->api('PATCH', "/api/documents/{$id}", [
            'title' => 'Mayor permit', 'doc_type' => 'Permit / License', 'department_id' => $this->hr->id, 'description' => 'City hall',
            'issued_on' => '2025-01-02', 'expires_on' => '2026-01-01', 'location_note' => 'Cabinet 3', 'code' => 'CHANGED',
        ])->assertOk()
            ->assertJsonPath('document.title', 'Mayor permit')
            ->assertJsonPath('document.code', 'TSI-PERMIT-1')
            ->assertJsonPath('document.department', 'HR')
            ->assertJsonPath('document.expiresOn', '2026-01-01')
            ->assertJsonPath('document.location', 'Cabinet 3')
            ->assertJsonPath('document.description', 'City hall');

        $this->assertSame('TSI-PERMIT-1', Document::find($id)->code);
        $this->assertSame(1, DocumentVersion::where('document_id', $id)->count());
        // the current version carries the corrected dates
        $version = DocumentVersion::where('document_id', $id)->first();
        $this->assertSame('2026-01-01', $version->expires_on->format('Y-m-d'));
        $this->assertSame('2025-01-02', $version->issued_on->format('Y-m-d'));

        $titleChange = collect($this->changes('documents', $id))->where('column_name', 'title')->last();
        $this->assertSame(['title', 'Business permit', 'Mayor permit'], array_values($titleChange));
        $this->assertContains('expires_on', array_column($this->changes('documents', $id), 'column_name'));
        $this->assertContains('expires_on', array_column($this->changes('document_versions', $version->id), 'column_name'));
        $this->assertContains('document.updated', DB::table('audit_logs')->pluck('action')->all());
    }

    public function test_only_the_fields_sent_change_and_an_empty_one_clears_it(): void
    {
        $id = $this->register(['expires_on' => '2026-05-05', 'location_note' => 'Shelf B']);

        $this->api('PATCH', "/api/documents/{$id}", ['location_note' => ''])->assertOk()
            ->assertJsonPath('document.location', '')
            ->assertJsonPath('document.title', 'Business permit')
            ->assertJsonPath('document.expiresOn', '2026-05-05');
    }

    public function test_saving_without_a_difference_logs_nothing(): void
    {
        $id = $this->register(['expires_on' => '2026-05-05']);
        $before = DB::table('audit_changes')->count();
        $actions = DB::table('audit_logs')->count();

        $this->api('PATCH', "/api/documents/{$id}", ['title' => 'Business permit', 'expires_on' => '2026-05-05'])->assertOk();

        $this->assertSame($before, DB::table('audit_changes')->count());
        $this->assertSame($actions + 1, DB::table('audit_logs')->count());   // the activity log still notes the save
    }

    public function test_bad_details_are_refused_and_nothing_changes(): void
    {
        $id = $this->register(['issued_on' => '2025-06-01', 'expires_on' => '2025-12-31']);

        $this->api('PATCH', "/api/documents/{$id}", ['title' => '   '])->assertStatus(422)->assertJsonPath('field', 'title');
        $this->api('PATCH', "/api/documents/{$id}", ['expires_on' => '2025-05-31'])->assertStatus(422)->assertJsonPath('field', 'expires_on');
        $this->api('PATCH', "/api/documents/{$id}", ['issued_on' => '2026-01-01'])->assertStatus(422)->assertJsonPath('field', 'expires_on');
        $this->api('PATCH', "/api/documents/{$id}", ['expires_on' => 'tomorrow'])->assertStatus(422)->assertJsonPath('field', 'expires_on');
        $this->api('PATCH', "/api/documents/{$id}", ['department_id' => 999])->assertStatus(422)->assertJsonPath('field', 'department_id');

        $this->assertSame('Business permit', Document::find($id)->title);
        $this->assertSame('2025-12-31', Document::find($id)->expires_on->format('Y-m-d'));
    }

    public function test_the_edit_needs_a_sign_in_and_an_existing_document(): void
    {
        $id = $this->register();
        $this->api('POST', '/api/logout');
        $this->api('PATCH', "/api/documents/{$id}", ['title' => 'x'])->assertStatus(401);

        $this->as('ana');
        $this->api('PATCH', '/api/documents/999', ['title' => 'x'])->assertStatus(404)->assertExactJson(['error' => 'route']);
    }

    public function test_editing_needs_the_update_tag_in_the_documents_department(): void
    {
        $id = $this->register(['department_id' => $this->hr->id]);
        $this->person('viewer', [$this->hr->id => ['view', 'download']]);
        $this->person('editor', [$this->hr->id => ['view', 'update']]);
        $this->person('other', [$this->acc->id => ['view', 'update']]);

        $this->as('viewer');
        $this->api('PATCH', "/api/documents/{$id}", ['title' => 'x'])->assertStatus(403);
        $this->as('other');
        $this->api('PATCH', "/api/documents/{$id}", ['title' => 'x'])->assertStatus(403);
        $this->as('editor');
        $this->api('PATCH', "/api/documents/{$id}", ['title' => 'New title'])->assertOk()->assertJsonPath('document.title', 'New title');
    }

    public function test_moving_a_document_needs_the_update_tag_in_the_new_department_too(): void
    {
        $id = $this->register(['department_id' => $this->hr->id]);
        $this->person('hr-only', [$this->hr->id => ['view', 'update']]);
        $this->person('both', [$this->hr->id => ['view', 'update'], $this->acc->id => ['view', 'update']]);

        $this->as('hr-only');
        $this->api('PATCH', "/api/documents/{$id}", ['department_id' => $this->acc->id])->assertStatus(403);
        $this->api('PATCH', "/api/documents/{$id}", ['department_id' => null])->assertStatus(403);   // "no department" is its own row
        $this->assertSame($this->hr->id, Document::find($id)->department_id);

        $this->as('both');
        $this->api('PATCH', "/api/documents/{$id}", ['department_id' => $this->acc->id])->assertOk()->assertJsonPath('document.department', 'Accounting');
        $this->assertContains('department_id', array_column($this->changes('documents', $id), 'column_name'));
    }

    public function test_a_document_cannot_be_moved_into_a_switched_off_department(): void
    {
        $id = $this->register();
        $this->acc->update(['active' => false]);

        $this->api('PATCH', "/api/documents/{$id}", ['department_id' => $this->acc->id])->assertStatus(422)->assertJsonPath('field', 'department_id');
        // but editing a document that already sits in one is fine
        $other = $this->register(['code' => 'TSI-OLD', 'department_id' => $this->hr->id]);
        $this->hr->update(['active' => false]);
        $this->api('PATCH', "/api/documents/{$other}", ['title' => 'Still editable'])->assertOk();
    }

    public function test_the_list_offers_the_departments_a_document_may_be_moved_into(): void
    {
        $this->person('editor', [$this->hr->id => ['view', 'update'], $this->acc->id => ['view']]);
        $this->as('editor');

        $this->api('GET', '/api/documents')->assertOk()
            ->assertJsonPath('editDepartments', [['id' => $this->hr->id, 'name' => 'HR']])
            ->assertJsonPath('canEditNone', false);
    }

    // ---- renewing ----

    public function test_a_renewal_is_a_new_version_with_new_dates_and_the_old_dates_stay_with_the_old_version(): void
    {
        $id = $this->register(['issued_on' => '2025-01-01', 'expires_on' => '2025-12-31']);

        $this->renew($id, ['issued_on' => '2026-01-01', 'expires_on' => '2026-12-31', 'change_note' => 'Renewed for 2026'])->assertCreated()
            ->assertJsonPath('document.rev', 1)
            ->assertJsonPath('document.issuedOn', '2026-01-01')
            ->assertJsonPath('document.expiresOn', '2026-12-31')
            ->assertJsonPath('document.versions.0.issuedOn', '2026-01-01')
            ->assertJsonPath('document.versions.0.expiresOn', '2026-12-31')
            ->assertJsonPath('document.versions.1.issuedOn', '2025-01-01')
            ->assertJsonPath('document.versions.1.expiresOn', '2025-12-31');

        $this->assertSame(2, DocumentVersion::where('document_id', $id)->count());
        $this->assertSame(['sign-in', 'document.created', 'document.renewed'], DB::table('audit_logs')->orderBy('id')->pluck('action')->all());
        $expires = collect($this->changes('documents', $id))->where('column_name', 'expires_on')->values();
        $this->assertSame([null, '2025-12-31'], [$expires[0]['value_before'], $expires[0]['value_after']]);
        $this->assertSame(['2025-12-31', '2026-12-31'], [$expires[1]['value_before'], $expires[1]['value_after']]);
    }

    public function test_a_renewal_with_dates_the_wrong_way_round_changes_nothing(): void
    {
        $id = $this->register(['issued_on' => '2025-01-01', 'expires_on' => '2025-12-31']);

        $this->renew($id, ['issued_on' => '2026-06-01', 'expires_on' => '2026-01-01'])->assertStatus(422)->assertJsonPath('field', 'expires_on');

        $this->assertSame(1, DocumentVersion::where('document_id', $id)->count());
        $this->assertSame('2025-12-31', Document::find($id)->expires_on->format('Y-m-d'));
        Storage::disk('local')->assertMissing('documents/'.$id.'/permit-2027.pdf');
    }

    public function test_a_plain_new_version_keeps_the_document_dates_and_carries_them(): void
    {
        $id = $this->register(['issued_on' => '2025-01-01', 'expires_on' => '2025-12-31']);

        $this->renew($id, [])->assertCreated()
            ->assertJsonPath('document.expiresOn', '2025-12-31')
            ->assertJsonPath('document.versions.0.expiresOn', '2025-12-31');
        $this->assertSame(['sign-in', 'document.created', 'version.created'], DB::table('audit_logs')->orderBy('id')->pluck('action')->all());
    }

    public function test_a_renewal_needs_the_update_tag(): void
    {
        $id = $this->register(['department_id' => $this->hr->id, 'expires_on' => '2025-12-31']);
        $this->person('viewer', [$this->hr->id => ['view', 'download']]);
        $this->as('viewer');

        $this->renew($id, ['expires_on' => '2026-12-31'])->assertStatus(403);
        $this->assertSame('2025-12-31', Document::find($id)->expires_on->format('Y-m-d'));
    }

    // ---- the status ----

    public function test_the_server_says_where_each_document_stands_against_its_expiry_date(): void
    {
        $this->register(['code' => 'NONE']);
        $this->register(['code' => 'FAR', 'expires_on' => $this->day(31)]);
        $this->register(['code' => 'EDGE', 'expires_on' => $this->day(30)]);
        $this->register(['code' => 'TODAY', 'expires_on' => $this->day(0)]);
        $this->register(['code' => 'GONE', 'expires_on' => $this->day(-1)]);
        $this->register(['code' => 'LONG', 'expires_on' => $this->day(-45)]);

        $rows = collect($this->api('GET', '/api/documents')->assertOk()->json('documents'))->keyBy('code');

        $this->assertSame(['none', null], [$rows['NONE']['expiryStatus'], $rows['NONE']['daysLeft']]);
        $this->assertSame(['active', 31], [$rows['FAR']['expiryStatus'], $rows['FAR']['daysLeft']]);
        $this->assertSame(['expiring', 30], [$rows['EDGE']['expiryStatus'], $rows['EDGE']['daysLeft']]);
        $this->assertSame(['expiring', 0], [$rows['TODAY']['expiryStatus'], $rows['TODAY']['daysLeft']]);
        $this->assertSame(['expired', -1], [$rows['GONE']['expiryStatus'], $rows['GONE']['daysLeft']]);
        $this->assertSame(['expired', -45], [$rows['LONG']['expiryStatus'], $rows['LONG']['daysLeft']]);
    }

    public function test_renewing_moves_a_document_out_of_expired(): void
    {
        $id = $this->register(['expires_on' => $this->day(-10)]);
        $this->api('GET', "/api/documents/{$id}")->assertJsonPath('document.expiryStatus', 'expired');

        $this->renew($id, ['issued_on' => $this->day(0), 'expires_on' => $this->day(365)])->assertCreated()
            ->assertJsonPath('document.expiryStatus', 'active');
    }
}
