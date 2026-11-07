<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes the append-only audit_logs table: who did what, from where. Rows are only ever added; the database itself
 * refuses to change or delete them (see the create_audit_logs_table migration).
 */
final class AuditLog
{
    public static function record(
        ?User $user,
        string $action,
        string $note = '',
        ?string $username = null,
        ?string $entityType = null,
        ?string $entityId = null,
    ): void {
        $request = request();
        $console = app()->runningInConsole() && ! app()->runningUnitTests();

        DB::table('audit_logs')->insert([
            'occurred_at' => now()->utc(),
            'actor_user_id' => $user?->id,
            'actor_username' => $user?->username ?? $username ?? ($console ? 'console' : null),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'note' => self::clean($note),
            'ip' => $console ? null : $request->ip(),
            'user_agent' => $console ? null : Str::limit((string) $request->userAgent(), 255, ''),
        ]);
    }

    /** No line breaks or control characters, at most 500 characters. */
    private static function clean(string $text): string
    {
        return Str::limit(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '', 500, '');
    }
}
