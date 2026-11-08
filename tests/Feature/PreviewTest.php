<?php

namespace Tests\Feature;

use App\Auth\Access;
use App\Models\Department;
use App\Models\DocumentFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** Word, PowerPoint and OpenDocument files shown as a PDF, made by LibreOffice (a stand-in program here). */
class PreviewTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    private const FORM = ['X-Documentator' => '1', 'Accept' => 'application/json'];

    private string $script;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        User::factory()->create(['username' => 'ana', 'name' => 'Ana Reyes']);   // an administrator
        $this->signIn('ana')->assertOk();
        $this->script = sys_get_temp_dir().'/fake-soffice-'.uniqid().'.sh';
    }

    protected function tearDown(): void
    {
        @unlink($this->script);
        parent::tearDown();
    }

    /** A program that does what soffice --convert-to pdf does: writes <name>.pdf into --outdir (or fails). */
    private function converter(string $body = 'printf "%%PDF-1.4 converted" > "$outdir/$(basename "${in%%.*}").pdf"'): void
    {
        file_put_contents($this->script, "#!/bin/sh\noutdir=\nfor a in \"\$@\"; do\n  if [ \"\$prev\" = \"--outdir\" ]; then outdir=\"\$a\"; fi\n  prev=\"\$a\"\n  in=\"\$a\"\ndone\n{$body}\n");
        chmod($this->script, 0755);
        config(['documentator.soffice' => $this->script]);
    }

    private function upload(string $name, string $mime): int
    {
        $this->post('/api/documents', ['code' => 'D-'.uniqid(), 'title' => 'T', 'files' => [UploadedFile::fake()->create($name, 5, $mime)]], self::FORM)->assertCreated();

        return (int) DocumentFile::latest('id')->value('id');
    }

    public function test_without_libreoffice_there_is_no_preview_and_the_session_says_so(): void
    {
        $id = $this->upload('deck.ppt', 'application/vnd.ms-powerpoint');

        $this->api('GET', '/api/session')->assertJsonPath('user.previews', false);
        $this->api('GET', "/api/files/{$id}/preview")->assertStatus(404)->assertExactJson(['error' => 'preview_unavailable']);
    }

    public function test_a_word_file_comes_back_as_a_pdf_that_is_made_once_and_kept(): void
    {
        $this->converter();
        $id = $this->upload('letter.doc', 'application/msword');

        $this->api('GET', '/api/session')->assertJsonPath('user.previews', true);
        $response = $this->api('GET', "/api/files/{$id}/preview")->assertOk();
        $this->assertSame('application/pdf', explode(';', (string) $response->headers->get('Content-Type'))[0]);
        $this->assertStringStartsWith('%PDF', $response->streamedContent());
        $document = DocumentFile::find($id)->version->document;
        Storage::disk('local')->assertExists("previews/{$document->id}/{$id}.pdf");

        // the second time it is not converted again: break the converter and it still works
        file_put_contents($this->script, "#!/bin/sh\nexit 1\n");
        $this->api('GET', "/api/files/{$id}/preview")->assertOk();

        $this->assertSame(2, DB::table('audit_logs')->where('action', 'file.viewed')->count());
        $this->assertNotNull(DocumentFile::find($id)->last_viewed_at);
    }

    public function test_only_the_kinds_libreoffice_is_given_are_converted(): void
    {
        $this->converter();
        $pdf = $this->upload('plain.pdf', 'application/pdf');
        $zip = $this->upload('bundle.zip', 'application/zip');

        $this->api('GET', "/api/files/{$pdf}/preview")->assertStatus(404)->assertJsonPath('error', 'preview_unavailable');
        $this->api('GET', "/api/files/{$zip}/preview")->assertStatus(404)->assertJsonPath('error', 'preview_unavailable');
    }

    public function test_a_failed_conversion_says_so_and_keeps_nothing(): void
    {
        $this->converter('exit 1');
        $id = $this->upload('deck.ppt', 'application/vnd.ms-powerpoint');

        $this->api('GET', "/api/files/{$id}/preview")->assertStatus(422)->assertExactJson(['error' => 'preview_failed']);
        $document = DocumentFile::find($id)->version->document;
        Storage::disk('local')->assertMissing("previews/{$document->id}/{$id}.pdf");
        $this->assertSame([], glob(sys_get_temp_dir().'/documentator-preview-*') ?: []);   // the throwaway folder is gone
    }

    public function test_the_preview_needs_the_download_tag_for_the_documents_department(): void
    {
        $this->converter();
        $hr = Department::factory()->create(['name' => 'HR']);
        $this->post('/api/documents', ['code' => 'HR-1', 'title' => 'T', 'department_id' => $hr->id, 'files' => [UploadedFile::fake()->create('a.doc', 5, 'application/msword')]], self::FORM)->assertCreated();
        $id = (int) DocumentFile::latest('id')->value('id');

        $roleId = DB::table('roles')->insertGetId(['key' => 'seer', 'name' => 'Seer', 'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('role_grants')->insert(['role_id' => $roleId, 'department_id' => $hr->id, 'permission' => Access::TAGS['view']]);
        User::factory()->create(['username' => 'sam', 'name' => 'Sam', 'role' => 'seer']);
        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie(config('session.cookie'), '');
        $this->signIn('sam')->assertOk();

        // viewing without the download tag is refused before anything is converted
        $this->api('GET', "/api/files/{$id}/preview")->assertStatus(403);
        $this->api('POST', '/api/logout');
        $this->api('GET', "/api/files/{$id}/preview")->assertStatus(401);
    }

    public function test_an_archived_files_preview_is_made_from_its_zip(): void
    {
        $this->converter();
        $first = $this->upload('letter.doc', 'application/msword');
        $document = DocumentFile::find($first)->version->document;
        $this->post("/api/documents/{$document->id}/versions", ['files' => [UploadedFile::fake()->create('letter2.doc', 5, 'application/msword')]], self::FORM)->assertCreated();
        $this->api('POST', '/api/versions/'.DocumentFile::find($first)->version_id.'/archive')->assertOk();
        Storage::disk('local')->assertMissing(DocumentFile::find($first)->storage_path);   // only the zip holds it now

        $this->api('GET', "/api/files/{$first}/preview")->assertOk();
    }
}
