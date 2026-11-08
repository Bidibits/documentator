<?php

namespace Tests\Feature;

use App\Auth\Access;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\DocumentVersion;
use App\Models\Folder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** "Tell me N days before", the bell's list, and the sample documents. */
class RemindersAndDemoTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private const FORM = ['X-Documentator' => '1', 'Accept' => 'application/json'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        User::factory()->create(['username' => 'ana', 'name' => 'Ana Reyes']);   // an administrator
        $this->as('ana');
    }

    private function as(string $username): void
    {
        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie(config('session.cookie'), '');
        $this->signIn($username)->assertOk();
    }

    private function day(int $offset): string
    {
        return Carbon::today(config('documentator.timezone'))->addDays($offset)->format('Y-m-d');
    }

    private function codes(string $uri): array
    {
        return array_column($this->api('GET', $uri)->assertOk()->json('documents'), 'code');
    }

    // ---- tell me N days before ----

    public function test_a_document_can_set_how_many_days_before_it_expires_it_is_flagged(): void
    {
        Document::factory()->create(['code' => 'DEFAULT-45', 'expires_on' => $this->day(45)]);                      // 45 days: beyond the default 30
        Document::factory()->create(['code' => 'OWN-60', 'expires_on' => $this->day(45), 'remind_days' => 60]);       // flagged from 60 days
        Document::factory()->create(['code' => 'OWN-7-IN-10', 'expires_on' => $this->day(10), 'remind_days' => 7]);  // not yet: only from 7 days
        Document::factory()->create(['code' => 'OWN-7-IN-7', 'expires_on' => $this->day(7), 'remind_days' => 7]);
        Document::factory()->create(['code' => 'EXPIRED', 'expires_on' => $this->day(-3), 'remind_days' => 1]);

        $rows = collect($this->api('GET', '/api/documents')->json('documents'))->keyBy('code');
        $this->assertSame(['active', 30], [$rows['DEFAULT-45']['expiryStatus'], $rows['DEFAULT-45']['leadDays']]);
        $this->assertSame(['expiring', 60, 60], [$rows['OWN-60']['expiryStatus'], $rows['OWN-60']['leadDays'], $rows['OWN-60']['remindDays']]);
        $this->assertSame('active', $rows['OWN-7-IN-10']['expiryStatus']);
        $this->assertSame('expiring', $rows['OWN-7-IN-7']['expiryStatus']);
        $this->assertSame('expired', $rows['EXPIRED']['expiryStatus']);

        // the database filters agree with the status shown
        $this->assertEqualsCanonicalizing(['OWN-60', 'OWN-7-IN-7', 'EXPIRED'], $this->codes('/api/documents?status=attention'));
        $this->assertEqualsCanonicalizing(['OWN-60', 'OWN-7-IN-7'], $this->codes('/api/documents?status=expiring'));
        $this->assertEqualsCanonicalizing(['DEFAULT-45', 'OWN-7-IN-10'], $this->codes('/api/documents?status=active'));
        $this->assertSame(['EXPIRED'], $this->codes('/api/documents?status=expired'));
        $this->api('GET', '/api/documents')->assertJsonPath('expiringCount', 3);
    }

    public function test_the_days_are_set_when_registering_and_corrected_in_edit_details_and_logged(): void
    {
        $id = $this->post('/api/documents', ['code' => 'P-1', 'title' => 'Permit', 'expires_on' => $this->day(50), 'remind_days' => 60,
            'files' => [UploadedFile::fake()->create('p.pdf', 5, 'application/pdf')]], self::FORM)->assertCreated()
            ->assertJsonPath('document.remindDays', 60)->assertJsonPath('document.expiryStatus', 'expiring')->json('document.id');

        $this->api('PATCH', "/api/documents/{$id}", ['remind_days' => 14])->assertOk()
            ->assertJsonPath('document.remindDays', 14)->assertJsonPath('document.leadDays', 14)->assertJsonPath('document.expiryStatus', 'active');
        $this->api('PATCH', "/api/documents/{$id}", ['remind_days' => null])->assertOk()
            ->assertJsonPath('document.remindDays', null)->assertJsonPath('document.leadDays', 30);

        $entry = collect($this->api('GET', "/api/audits?table=documents&record={$id}")->json('entries'))->where('column', 'remind_days')->first();
        $this->assertSame('Remind (days before)', $entry['columnLabel']);
        $this->assertSame(['14', null], [$entry['before'], $entry['after']]);
    }

    public function test_only_one_to_365_days_are_accepted(): void
    {
        $id = Document::factory()->create(['code' => 'P-1'])->id;

        foreach ([0, -3, 366, 'soon', 1.5] as $bad) {
            $this->api('PATCH', "/api/documents/{$id}", ['remind_days' => $bad])->assertStatus(422)->assertJsonPath('field', 'remind_days');
        }
        $this->api('PATCH', "/api/documents/{$id}", ['remind_days' => 365])->assertOk();
        $this->assertSame(365, Document::find($id)->remind_days);
    }

    // ---- the bell ----

    public function test_the_bell_lists_the_ten_closest_and_counts_all_of_them(): void
    {
        for ($i = 1; $i <= 14; $i++) {
            Document::factory()->create(['code' => sprintf('D-%02d', $i), 'expires_on' => $this->day($i - 5)]);   // five expired, then up to day 9
        }
        Document::factory()->create(['code' => 'FAR', 'expires_on' => $this->day(200)]);
        Document::factory()->create(['code' => 'NONE']);

        $response = $this->api('GET', '/api/reminders')->assertOk();
        $this->assertSame(14, $response->json('count'));
        $this->assertCount(10, $response->json('items'));
        $this->assertSame('D-01', $response->json('items.0.code'));   // the longest overdue first
        $this->assertSame('expired', $response->json('items.0.expiryStatus'));
        $this->assertSame('D-10', $response->json('items.9.code'));
        $this->assertNotContains('FAR', array_column($response->json('items'), 'code'));
    }

    public function test_the_bell_is_empty_when_nothing_is_due(): void
    {
        Document::factory()->create(['code' => 'FAR', 'expires_on' => $this->day(200)]);

        $this->api('GET', '/api/reminders')->assertOk()->assertExactJson(['count' => 0, 'items' => []]);
    }

    public function test_the_bell_only_shows_what_the_person_may_view_and_needs_a_sign_in(): void
    {
        $hr = Department::factory()->create(['name' => 'HR']);
        $acc = Department::factory()->create(['name' => 'Accounting']);
        Document::factory()->create(['code' => 'HR-1', 'department_id' => $hr->id, 'expires_on' => $this->day(2)]);
        Document::factory()->create(['code' => 'ACC-1', 'department_id' => $acc->id, 'expires_on' => $this->day(2)]);
        $role = DB::table('roles')->insertGetId(['key' => 'hr-only', 'name' => 'HR only', 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('role_grants')->insert(['role_id' => $role, 'department_id' => $hr->id, 'permission' => Access::TAGS['view']]);
        User::factory()->create(['username' => 'hana', 'name' => 'Hana', 'role' => 'hr-only']);

        $this->as('hana');
        $response = $this->api('GET', '/api/reminders')->assertOk();
        $this->assertSame(1, $response->json('count'));
        $this->assertSame(['HR-1'], array_column($response->json('items'), 'code'));

        $this->api('POST', '/api/logout');
        $this->api('GET', '/api/reminders')->assertStatus(401);
    }

    // ---- the sample documents ----

    public function test_the_sample_documents_cover_every_kind_of_deadline_and_open_as_real_files(): void
    {
        $this->artisan('documentator:demo')->assertSuccessful();

        $this->assertSame(15, Document::where('code', 'like', 'DEMO-%')->count());
        $rows = collect($this->api('GET', '/api/documents?per=50')->json('documents'))->keyBy('code');
        $this->assertSame('expired', $rows['DEMO-01']['expiryStatus']);   // 45 days ago
        $this->assertSame('expired', $rows['DEMO-02']['expiryStatus']);   // yesterday
        $this->assertSame(['expiring', 0], [$rows['DEMO-03']['expiryStatus'], $rows['DEMO-03']['daysLeft']]);   // today
        $this->assertSame(['expiring', 7], [$rows['DEMO-04']['expiryStatus'], $rows['DEMO-04']['leadDays']]);
        $this->assertSame('expiring', $rows['DEMO-07']['expiryStatus']);   // 45 days away, but its own 60-day warning
        $this->assertSame('active', $rows['DEMO-08']['expiryStatus']);     // 45 days away, the default 30
        $this->assertSame('none', $rows['DEMO-11']['expiryStatus']);
        $this->assertGreaterThanOrEqual(8, $this->api('GET', '/api/reminders')->json('count'));

        // a renewed one keeps its old dates on the old version
        $renewed = $this->api('GET', '/api/documents/'.$rows['DEMO-12']['id'])->assertOk()->json('document');
        $this->assertCount(2, $renewed['versions']);
        $this->assertNotSame($renewed['versions'][0]['expiresOn'], $renewed['versions'][1]['expiresOn']);

        // the folders are a small tree, with colors and looks
        $folders = collect($this->api('GET', '/api/folders')->json('folders'))->keyBy('name');
        $this->assertSame('Contracts / 2026', $folders['2026']['path']);
        $this->assertSame('binder', $folders['Permits']['icon']);

        // every file really opens, and is the kind it says
        foreach (DocumentFile::all() as $file) {
            $response = $this->api('GET', "/api/files/{$file->id}")->assertOk();
            $this->assertSame($file->size, strlen($response->streamedContent()));
        }
        $pdf = DocumentFile::where('original_name', 'business-permit-2026.pdf')->first();
        $this->assertStringStartsWith('%PDF-1.4', Storage::disk('local')->get($pdf->storage_path));
        $docx = Storage::disk('local')->path(DocumentFile::where('original_name', 'warehouse-lease.docx')->first()->storage_path);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($docx) === true && $zip->locateName('word/document.xml') !== false);
        $png = Storage::disk('local')->get(DocumentFile::where('original_name', 'lease-map.png')->first()->storage_path);
        $this->assertStringStartsWith("\x89PNG", $png);
    }

    public function test_loading_the_samples_twice_adds_nothing_and_removing_takes_only_them_away(): void
    {
        $mine = Document::factory()->create(['code' => 'MINE-1', 'title' => 'Not a sample']);
        $keep = Folder::create(['name' => 'Permits', 'created_by' => 1, 'updated_by' => 1]);   // someone's own folder with the same name
        Document::factory()->create(['code' => 'MINE-2', 'folder_id' => $keep->id]);

        $this->artisan('documentator:demo')->assertSuccessful();
        $count = Document::count();
        $folders = Folder::count();
        $this->artisan('documentator:demo')->assertSuccessful();
        $this->assertSame([$count, $folders], [Document::count(), Folder::count()]);
        $this->assertSame(1, Folder::whereRaw('lower(name) = ?', ['permits'])->count());   // the existing one was used, not copied

        $this->artisan('documentator:demo', ['--remove' => true])->assertSuccessful();
        $this->assertSame(0, Document::where('code', 'like', 'DEMO-%')->count());
        $this->assertNotNull(Document::find($mine->id));
        $this->assertNotNull(Folder::find($keep->id));   // it still holds MINE-2, so it stays
        $this->assertSame(0, DocumentVersion::whereNotIn('document_id', Document::pluck('id'))->count());
        $this->assertSame([], Storage::disk('local')->allFiles());   // the factory documents have no files, so every stored file was a sample
    }

    public function test_the_samples_need_an_account_to_belong_to(): void
    {
        User::query()->delete();

        $this->artisan('documentator:demo')->assertFailed();
        $this->assertSame(0, Document::count());
    }
}
