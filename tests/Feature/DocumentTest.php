<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->ana = User::factory()->create(['username' => 'ana', 'name' => 'Ana Reyes']);
    }

    private function signedIn(): void
    {
        $this->signIn('ana')->assertOk();
    }

    private function pdf(string $name = 'contract.pdf', int $kilobytes = 20): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
    }

    /** Sends the registration form the way the page does: multipart, not JSON. */
    private function register(array $fields = [], ?array $files = null): TestResponse
    {
        $fields += ['code' => 'TSI-26-11-08-01', 'title' => 'Office lease'];
        $fields['files'] = $files ?? [$this->pdf()];

        return $this->post('/api/documents', $fields, ['X-Documentator' => '1', 'Accept' => 'application/json']);
    }

    private function actions(): array
    {
        return DB::table('audit_logs')->orderBy('id')->pluck('action')->all();
    }

    // ---- who may use it ----

    public function test_every_document_route_needs_a_signed_in_person(): void
    {
        $this->api('GET', '/api/documents')->assertStatus(401)->assertExactJson(['error' => 'signin']);
        $this->api('GET', '/api/documents/suggest-code')->assertStatus(401);
        $this->api('GET', '/api/documents/1')->assertStatus(401);
        $this->api('GET', '/api/files/1')->assertStatus(401);
        $this->register()->assertStatus(401);

        $this->assertSame(0, Document::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    // ---- the suggested code ----

    public function test_the_first_suggestion_of_the_day_ends_in_01_and_the_day_is_the_office_day(): void
    {
        $this->signedIn();
        // 17:00 UTC on the 8th is 01:00 on the 9th in Manila
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(17, 0)->utc());

        $this->api('GET', '/api/documents/suggest-code')->assertOk()->assertExactJson(['code' => 'TSI-26-11-09-01']);
    }

    public function test_the_suggestion_goes_on_after_the_highest_number_taken_that_day(): void
    {
        $this->signedIn();
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(4, 0)->utc());
        Document::factory()->create(['code' => 'TSI-26-11-08-01']);
        Document::factory()->create(['code' => 'TSI-26-11-08-04']);

        $this->api('GET', '/api/documents/suggest-code')->assertExactJson(['code' => 'TSI-26-11-08-05']);
    }

    public function test_the_suggestion_ignores_other_days_other_shapes_and_free_text_codes(): void
    {
        $this->signedIn();
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(4, 0)->utc());
        foreach (['TSI-26-11-07-09', 'TSI-26-11-08-A', 'RandomCodeafe', 'TSI-26-11-08-', 'TSI-26-11-08-07-extra'] as $code) {
            Document::factory()->create(['code' => $code]);
        }

        $this->api('GET', '/api/documents/suggest-code')->assertExactJson(['code' => 'TSI-26-11-08-01']);
    }

    public function test_the_suggestion_keeps_counting_past_99(): void
    {
        $this->signedIn();
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(4, 0)->utc());
        Document::factory()->create(['code' => 'TSI-26-11-08-99']);

        $this->api('GET', '/api/documents/suggest-code')->assertExactJson(['code' => 'TSI-26-11-08-100']);
    }

    public function test_the_suggestion_uses_the_configured_prefix(): void
    {
        $this->signedIn();
        config(['documentator.code_prefix' => 'HR']);
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(4, 0)->utc());

        $this->api('GET', '/api/documents/suggest-code')->assertExactJson(['code' => 'HR-26-11-08-01']);
    }

    // ---- registering ----

    public function test_a_document_is_registered_with_rev_0_and_its_file(): void
    {
        $this->signedIn();

        $response = $this->register([
            'doc_type' => 'Contract', 'department' => 'Admin', 'description' => 'Signed copy',
            'issued_on' => '2026-11-01', 'expires_on' => '2027-10-31', 'location_note' => 'Cabinet 3, shelf B',
        ])->assertCreated();

        $response->assertJsonPath('document.code', 'TSI-26-11-08-01')
            ->assertJsonPath('document.title', 'Office lease')
            ->assertJsonPath('document.rev', 0)
            ->assertJsonPath('document.fileCount', 1)
            ->assertJsonPath('document.createdBy', 'Ana Reyes')
            ->assertJsonPath('document.issuedOn', '2026-11-01')
            ->assertJsonPath('document.expiresOn', '2027-10-31')
            ->assertJsonPath('document.location', 'Cabinet 3, shelf B')
            ->assertJsonPath('document.versions.0.rev', 0)
            ->assertJsonPath('document.versions.0.files.0.name', 'contract.pdf')
            ->assertJsonMissingPath('document.versions.0.files.0.storage_path');

        $document = Document::firstOrFail();
        $this->assertSame($this->ana->id, $document->created_by);
        $this->assertSame($document->versions()->first()->id, $document->current_version_id);

        $file = DocumentFile::firstOrFail();
        $this->assertSame(20 * 1024, (int) $file->size);
        $this->assertSame(64, strlen($file->sha256));
        Storage::disk('local')->assertExists($file->storage_path);
        $this->assertSame(['sign-in', 'document.created'], $this->actions());
    }

    public function test_a_code_can_be_any_text_not_only_the_suggested_pattern(): void
    {
        $this->signedIn();

        $this->register(['code' => 'RandomCodeafe'])->assertCreated()->assertJsonPath('document.code', 'RandomCodeafe');
        $this->register(['code' => '  Board Res. no. 7/2026  ', 'title' => 'Another'])->assertCreated()
            ->assertJsonPath('document.code', 'Board Res. no. 7/2026');
    }

    public function test_a_packet_can_have_several_files_in_order(): void
    {
        $this->signedIn();

        $this->register([], [$this->pdf('main.pdf'), $this->pdf('annex-a.pdf'), UploadedFile::fake()->create('scan.jpg', 30, 'image/jpeg')])
            ->assertCreated()
            ->assertJsonPath('document.fileCount', 3)
            ->assertJsonPath('document.versions.0.files.0.name', 'main.pdf')
            ->assertJsonPath('document.versions.0.files.2.name', 'scan.jpg');

        $this->assertCount(3, Storage::disk('local')->allFiles());
    }

    public function test_a_docx_is_accepted(): void
    {
        $this->signedIn();
        $docx = UploadedFile::fake()->create('letter.docx', 12, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->register([], [$docx])->assertCreated();
    }

    public function test_the_code_is_unique_whatever_its_case(): void
    {
        $this->signedIn();
        $this->register(['code' => 'RandomCodeafe'])->assertCreated();

        $this->register(['code' => 'randomcodeAFE'])
            ->assertStatus(409)->assertExactJson(['error' => 'code_taken']);

        $this->assertSame(1, Document::count());
        $this->assertCount(1, Storage::disk('local')->allFiles(), 'the refused upload left no file behind');
    }

    public function test_a_taken_suggested_code_comes_back_with_the_next_one(): void
    {
        $this->signedIn();
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(4, 0)->utc());
        $this->register(['code' => 'TSI-26-11-08-01'])->assertCreated();

        $this->register(['code' => 'TSI-26-11-08-01', 'title' => 'Second'])
            ->assertStatus(409)->assertExactJson(['error' => 'code_taken', 'suggestion' => 'TSI-26-11-08-02']);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badForms(): array
    {
        return [
            'no title' => [['title' => ''], 'title'],
            'no code' => [['code' => ''], 'code'],
            'code too long' => [['code' => str_repeat('a', 65)], 'code'],
            'code with a line break' => [['code' => "A\nB"], 'code'],
            'title too long' => [['title' => str_repeat('a', 201)], 'title'],
            'type too long' => [['doc_type' => str_repeat('a', 65)], 'doc_type'],
            'department too long' => [['department' => str_repeat('a', 101)], 'department'],
            'description too long' => [['description' => str_repeat('a', 2001)], 'description'],
            'location too long' => [['location_note' => str_repeat('a', 201)], 'location_note'],
            'issued is not a date' => [['issued_on' => 'next week'], 'issued_on'],
            'expires is not a date' => [['expires_on' => '31/12/2026'], 'expires_on'],
            'expires before it was issued' => [['issued_on' => '2026-11-08', 'expires_on' => '2026-11-07'], 'expires_on'],
        ];
    }

    #[DataProvider('badForms')]
    public function test_a_bad_form_is_refused_and_names_the_field(array $fields, string $field): void
    {
        $this->signedIn();

        $this->register($fields)->assertStatus(422)->assertJsonPath('error', 'invalid')->assertJsonPath('field', $field);

        $this->assertSame(0, Document::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_document_needs_at_least_one_file(): void
    {
        $this->signedIn();

        $this->post('/api/documents', ['code' => 'X1', 'title' => 'No file'], ['X-Documentator' => '1', 'Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('field', 'files');
    }

    public function test_at_most_ten_files(): void
    {
        $this->signedIn();
        $files = array_map(fn ($i) => $this->pdf("f{$i}.pdf"), range(1, 11));

        $this->register([], $files)->assertStatus(422)->assertJsonPath('field', 'files');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_file_over_the_size_limit_is_refused(): void
    {
        $this->signedIn();

        $this->register([], [$this->pdf('big.pdf', 25 * 1024 + 1)])->assertStatus(422)->assertJsonPath('field', 'files');
    }

    public function test_a_file_of_the_wrong_kind_is_refused_whatever_its_name_says(): void
    {
        $this->signedIn();

        $this->register([], [UploadedFile::fake()->create('program.exe', 10, 'application/x-msdownload')])
            ->assertStatus(422)->assertJsonPath('field', 'files');
        // a program renamed to .pdf is still a program
        $this->register([], [UploadedFile::fake()->create('invoice.pdf', 10, 'application/x-msdownload')])
            ->assertStatus(422)->assertJsonPath('field', 'files');
        // a PDF renamed to .exe is refused by its name
        $this->register([], [UploadedFile::fake()->create('invoice.exe', 10, 'application/pdf')])
            ->assertStatus(422)->assertJsonPath('field', 'files');

        $this->assertSame(0, Document::count());
    }

    public function test_the_stored_name_is_made_up_and_the_shown_name_is_cleaned(): void
    {
        $this->signedIn();

        $this->register([], [$this->pdf('../../etc/evil name.pdf')])->assertCreated();

        $file = DocumentFile::firstOrFail();
        $this->assertSame('evil name.pdf', $file->original_name);
        $this->assertMatchesRegularExpression('#^documents/\d+/[0-9a-f-]{36}\.pdf$#', $file->storage_path);
    }

    // ---- reading ----

    public function test_the_list_is_newest_first_and_offers_the_departments_and_types_in_use(): void
    {
        $this->signedIn();
        $this->register(['code' => 'A', 'title' => 'First', 'department' => 'HR', 'doc_type' => 'Form'])->assertCreated();
        $this->register(['code' => 'B', 'title' => 'Second', 'department' => 'Accounting', 'doc_type' => 'Form'])->assertCreated();
        $this->register(['code' => 'C', 'title' => 'Third'])->assertCreated();

        $this->api('GET', '/api/documents')->assertOk()
            ->assertJsonPath('documents.0.title', 'Third')
            ->assertJsonPath('documents.2.title', 'First')
            ->assertJsonPath('documents.1.fileCount', 1)
            ->assertJsonPath('departments', ['Accounting', 'HR'])
            ->assertJsonPath('types', ['Form']);
    }

    public function test_one_document_comes_with_its_versions_and_files(): void
    {
        $this->signedIn();
        $id = $this->register(['description' => 'Signed copy'])->assertCreated()->json('document.id');

        $this->api('GET', "/api/documents/{$id}")->assertOk()
            ->assertJsonPath('document.description', 'Signed copy')
            ->assertJsonPath('document.versions.0.createdBy', 'Ana Reyes')
            ->assertJsonPath('document.versions.0.files.0.name', 'contract.pdf');
    }

    public function test_an_unknown_document_is_a_404_in_the_error_shape(): void
    {
        $this->signedIn();

        $this->api('GET', '/api/documents/999')->assertStatus(404)->assertExactJson(['error' => 'route']);
    }

    // ---- downloading ----

    public function test_a_file_downloads_as_an_attachment_and_is_audited(): void
    {
        $this->signedIn();
        $id = $this->register()->assertCreated()->json('document.versions.0.files.0.id');

        $response = $this->api('GET', "/api/files/{$id}")->assertOk();

        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('contract.pdf', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(['sign-in', 'document.created', 'file.downloaded'], $this->actions());
        $this->assertSame('TSI-26-11-08-01', DB::table('audit_logs')->where('action', 'file.downloaded')->value('entity_id'));
    }

    public function test_the_downloaded_bytes_are_the_uploaded_bytes(): void
    {
        $this->signedIn();
        $upload = UploadedFile::fake()->createWithContent('note.pdf', "%PDF-1.4\nhello\n");
        $id = $this->register([], [$upload])->assertCreated()->json('document.versions.0.files.0.id');

        $body = $this->api('GET', "/api/files/{$id}")->assertOk()->streamedContent();

        $this->assertSame("%PDF-1.4\nhello\n", $body);
        $this->assertSame(hash('sha256', $body), DocumentFile::findOrFail($id)->sha256);
    }

    public function test_an_unknown_file_is_a_404_and_a_missing_one_says_so(): void
    {
        $this->signedIn();
        $id = $this->register()->assertCreated()->json('document.versions.0.files.0.id');

        $this->api('GET', '/api/files/999')->assertStatus(404);

        Storage::disk('local')->delete(DocumentFile::findOrFail($id)->storage_path);
        $this->api('GET', "/api/files/{$id}")->assertStatus(404)->assertExactJson(['error' => 'file_missing']);
    }

    public function test_the_storage_folder_is_not_reachable_from_the_web(): void
    {
        $this->signedIn();
        $this->register()->assertCreated();
        $path = DocumentFile::firstOrFail()->storage_path;

        $this->get('/storage/'.$path)->assertNotFound();
        $this->get('/'.$path)->assertNotFound();
    }
}
