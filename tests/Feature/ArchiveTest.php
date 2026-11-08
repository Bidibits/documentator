<?php

namespace Tests\Feature;

use App\Models\DocumentFile;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

/** Archiving a version: its files packed into one zip, still readable, and able to come back. */
class ArchiveTest extends TestCase
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

    private function text(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /** A document with Ver 0 (two files) and Ver 1 (one file). Returns [document id, Ver 0 id, Ver 1 id]. */
    private function document(string $code = 'LEASE-1'): array
    {
        $id = $this->post('/api/documents', ['code' => $code, 'title' => 'Lease', 'files' => [$this->text('a.txt', 'first A'), $this->text('a.txt', 'first B')]], self::FORM)
            ->assertCreated()->json('document.id');
        $this->post("/api/documents/{$id}/versions", ['files' => [$this->text('c.txt', 'second C')]], self::FORM)->assertCreated();

        return [$id, DocumentVersion::where('document_id', $id)->where('rev_no', 0)->value('id'), DocumentVersion::where('document_id', $id)->where('rev_no', 1)->value('id')];
    }

    private function archive(int $version): TestResponse
    {
        return $this->api('POST', "/api/versions/{$version}/archive");
    }

    private function restore(int $version): TestResponse
    {
        return $this->api('POST', "/api/versions/{$version}/restore");
    }

    public function test_archiving_packs_the_files_into_one_zip_and_removes_the_loose_copies(): void
    {
        [, $v0] = $this->document();
        $files = DocumentFile::where('version_id', $v0)->get();
        $paths = $files->pluck('storage_path')->all();

        $this->archive($v0)->assertOk()->assertJsonPath('version.archived', true)->assertJsonPath('version.archivedBy', 'Ana Reyes')
            ->assertJsonPath('version.files', 2);

        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
        $version = DocumentVersion::findOrFail($v0);
        $this->assertNotNull($version->archived_at);
        $this->assertSame(1, $version->archived_by);
        Storage::disk('local')->assertExists($version->archive_path);
        $this->assertSame(Storage::disk('local')->size($version->archive_path), (int) $version->archive_size);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($version->archive_path)));
        $this->assertSame(2, $zip->numFiles);   // both files, though both were called a.txt
        $zip->close();
        $this->assertSame(2, DocumentFile::where('version_id', $v0)->count());   // the records stay
    }

    public function test_an_archived_file_still_downloads_and_views_with_the_same_bytes_and_is_noted_as_used(): void
    {
        [, $v0] = $this->document();
        $a = DocumentFile::where('version_id', $v0)->orderBy('id')->first();
        $b = DocumentFile::where('version_id', $v0)->orderBy('id')->skip(1)->first();
        $this->archive($v0)->assertOk();

        $first = $this->api('GET', "/api/files/{$a->id}")->assertOk();
        $this->assertSame('first A', $first->streamedContent());
        $this->assertStringStartsWith('text/plain', $first->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $first->headers->get('Content-Disposition'));
        $this->assertSame('first B', $this->api('GET', "/api/files/{$b->id}?view=1")->assertOk()->streamedContent());

        $this->assertNotNull($a->fresh()->last_downloaded_at);
        $this->assertNotNull($b->fresh()->last_viewed_at);
        $this->assertContains('file.downloaded', DB::table('audit_logs')->pluck('action')->all());
    }

    public function test_an_archived_file_whose_zip_has_gone_says_so(): void
    {
        [, $v0] = $this->document();
        $file = DocumentFile::where('version_id', $v0)->first();
        $this->archive($v0)->assertOk();
        Storage::disk('local')->delete(DocumentVersion::findOrFail($v0)->archive_path);

        $this->api('GET', "/api/files/{$file->id}")->assertStatus(404)->assertExactJson(['error' => 'file_missing']);
    }

    public function test_restoring_unpacks_the_files_to_where_they_were_and_removes_the_zip(): void
    {
        [, $v0] = $this->document();
        $files = DocumentFile::where('version_id', $v0)->get();
        $zip = null;
        $this->archive($v0)->assertOk();
        $zip = DocumentVersion::findOrFail($v0)->archive_path;

        $this->restore($v0)->assertOk()->assertJsonPath('version.archived', false);

        foreach ($files as $file) {
            Storage::disk('local')->assertExists($file->storage_path);
            $this->assertSame($file->sha256, hash_file('sha256', Storage::disk('local')->path($file->storage_path)));
        }
        Storage::disk('local')->assertMissing($zip);
        $version = DocumentVersion::findOrFail($v0);
        $this->assertNull($version->archived_at);
        $this->assertNull($version->archive_path);
        $this->assertSame('first A', $this->api('GET', "/api/files/{$files[0]->id}")->streamedContent());
    }

    public function test_a_current_version_can_never_be_archived(): void
    {
        [$id, , $v1] = $this->document();

        $this->archive($v1)->assertStatus(409)->assertExactJson(['error' => 'current_version']);

        $this->assertNull(DocumentVersion::findOrFail($v1)->archived_at);
        Storage::disk('local')->assertExists(DocumentFile::where('version_id', $v1)->value('storage_path'));
        // and a version that stops being current can be: Ver 1 is current until Ver 2 arrives
        $this->post("/api/documents/{$id}/versions", ['files' => [$this->text('d.txt', 'third')]], self::FORM)->assertCreated();
        $this->archive($v1)->assertOk();
    }

    public function test_archiving_twice_or_restoring_what_is_not_archived_is_refused(): void
    {
        [, $v0] = $this->document();

        $this->restore($v0)->assertStatus(409)->assertExactJson(['error' => 'not_archived']);
        $this->archive($v0)->assertOk();
        $this->archive($v0)->assertStatus(409)->assertExactJson(['error' => 'already_archived']);
    }

    public function test_a_version_with_a_missing_file_is_not_archived_and_leaves_no_zip(): void
    {
        [, $v0] = $this->document();
        $files = DocumentFile::where('version_id', $v0)->get();
        Storage::disk('local')->delete($files[1]->storage_path);

        $this->archive($v0)->assertStatus(409)->assertJsonPath('error', 'file_missing')->assertJsonPath('file', 'a.txt');

        $this->assertNull(DocumentVersion::findOrFail($v0)->archived_at);
        Storage::disk('local')->assertExists($files[0]->storage_path);
        $this->assertSame([], Storage::disk('local')->allFiles('archive'));
    }

    public function test_a_zip_that_does_not_match_the_recorded_fingerprints_is_thrown_away_and_nothing_is_removed(): void
    {
        [, $v0] = $this->document();
        $file = DocumentFile::where('version_id', $v0)->first();
        $file->forceFill(['sha256' => str_repeat('0', 64)])->save();   // as if the stored bytes were not what was uploaded

        $this->archive($v0)->assertStatus(500)->assertExactJson(['error' => 'archive_failed']);

        $this->assertNull(DocumentVersion::findOrFail($v0)->archived_at);
        Storage::disk('local')->assertExists($file->storage_path);
        $this->assertSame([], Storage::disk('local')->allFiles('archive'));
        $this->assertSame(0, DB::table('audit_changes')->where('column_name', 'archived_at')->count());
    }

    public function test_archiving_and_restoring_are_logged_as_changes_to_the_version_and_as_events(): void
    {
        [, $v0] = $this->document();
        $this->archive($v0)->assertOk();
        $this->restore($v0)->assertOk();

        $rows = DB::table('audit_changes')->where('table_name', 'document_versions')->where('record_id', (string) $v0)
            ->whereIn('column_name', ['archived_at', 'archived_by'])->orderBy('id')->get();
        $this->assertSame(['archived_at', 'archived_by', 'archived_at', 'archived_by'], $rows->pluck('column_name')->all());
        $this->assertNull($rows[0]->value_before);
        $this->assertNotNull($rows[0]->value_after);
        $this->assertSame('1', $rows[1]->value_after);
        $this->assertNotNull($rows[2]->value_before);
        $this->assertNull($rows[2]->value_after);
        $this->assertSame(['Manual'], $rows->pluck('source')->unique()->values()->all());

        $this->assertSame(['version.archived', 'version.restored'], DB::table('audit_logs')->whereIn('action', ['version.archived', 'version.restored'])->orderBy('id')->pluck('action')->all());

        $shown = collect($this->api('GET', '/api/audits?table=document_versions')->json('entries'))->firstWhere('column', 'archived_by');
        $this->assertSame('Archived by', $shown['columnLabel']);
    }

    public function test_the_archive_list_shows_old_versions_most_recently_opened_first_and_never_a_current_one(): void
    {
        [$idA, $a0, $a1] = $this->document('A-1');
        [$idB, $b0] = $this->document('B-1');
        $this->post("/api/documents/{$idA}/versions", ['files' => [$this->text('e.txt', 'x')]], self::FORM)->assertCreated();   // A's Ver 1 is now old too
        $a1File = DocumentFile::where('version_id', $a1)->first();
        $b0File = DocumentFile::where('version_id', $b0)->first();

        $this->api('GET', "/api/files/{$a1File->id}?view=1")->assertOk();
        $this->travel(1)->minutes();
        $this->api('GET', "/api/files/{$b0File->id}")->assertOk();
        $this->archive($a1)->assertOk();

        $list = $this->api('GET', '/api/archive')->assertOk();
        $rows = $list->json('versions');

        $this->assertSame([$b0, $a1, $a0], array_column($rows, 'id'));   // newest opened first; never opened last
        $this->assertSame(['B-1', 'A-1', 'A-1'], array_column($rows, 'code'));
        $this->assertSame([false, true, false], array_column($rows, 'archived'));
        $this->assertSame(2, $rows[0]['files']);
        $this->assertSame(strlen('first A') + strlen('first B'), $rows[2]['size']);
        $this->assertSame('', $rows[2]['lastAccessedAt']);
        $this->assertNotSame('', $rows[0]['lastDownloadedAt']);
        $this->assertNotSame('', $rows[1]['lastViewedAt']);
        $this->assertSame('Ana Reyes', $rows[1]['archivedBy']);
        $this->assertSame(['versions' => 3, 'archived' => 1, 'archivedOriginalBytes' => strlen('second C'), 'archivedZipBytes' => (int) $rows[1]['archiveSize']], $list->json('summary'));
        $this->assertNotContains(DocumentVersion::where('document_id', $idB)->where('rev_no', 1)->value('id'), array_column($rows, 'id'));   // B's current
    }

    public function test_documents_show_which_versions_are_archived(): void
    {
        [$id, $v0] = $this->document();
        $this->archive($v0)->assertOk();

        $this->api('GET', '/api/documents')->assertJsonPath('documents.0.archivedVersions', 1);
        $detail = $this->api('GET', "/api/documents/{$id}")->assertOk();
        $this->assertSame([false, true], array_column($detail->json('document.versions'), 'archived'));   // Ver 1, then Ver 0
        $this->assertSame('Ana Reyes', $detail->json('document.versions.1.archivedBy'));
        $this->assertGreaterThan(0, $detail->json('document.versions.1.archiveSize'));
    }

    public function test_an_unknown_version_is_a_404(): void
    {
        $this->archive(999)->assertStatus(404);
    }
}
