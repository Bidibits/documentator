<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_row_records_who_did_what(): void
    {
        $user = User::factory()->create(['username' => 'ana']);

        AuditLog::record($user, 'document.created', 'a note', entityType: 'document', entityId: '7');

        $row = DB::table('audit_logs')->first();
        $this->assertSame($user->id, $row->actor_user_id);
        $this->assertSame('ana', $row->actor_username);
        $this->assertSame('document.created', $row->action);
        $this->assertSame('document', $row->entity_type);
        $this->assertSame('7', $row->entity_id);
        $this->assertSame('a note', $row->note);
        $this->assertNotNull($row->occurred_at);
    }

    public function test_line_breaks_and_control_characters_are_flattened_and_long_notes_cut(): void
    {
        AuditLog::record(null, 'x', "line one\nline two\x00".str_repeat('a', 600), 'ghost');

        $note = DB::table('audit_logs')->value('note');
        $this->assertStringNotContainsString("\n", $note);
        $this->assertStringNotContainsString("\x00", $note);
        $this->assertSame(500, mb_strlen($note));
    }

    public function test_a_row_cannot_be_updated(): void
    {
        AuditLog::record(null, 'sign-in', username: 'ana');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('audit_logs is append-only');

        DB::table('audit_logs')->update(['action' => 'rewritten']);
    }

    public function test_a_row_cannot_be_deleted(): void
    {
        AuditLog::record(null, 'sign-in', username: 'ana');

        try {
            DB::table('audit_logs')->delete();
            $this->fail('the delete went through');
        } catch (QueryException $e) {
            $this->assertStringContainsString('audit_logs is append-only', $e->getMessage());
        }

        $this->assertSame(1, DB::table('audit_logs')->count());
    }
}
