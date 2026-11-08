<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes audit_changes: one row per column a save changed, so "every change to this document's title" is a question the
 * database can answer. Same rules as KVNsys's audit trail. Call it inside the transaction of the save, so a change is
 * never kept without its record and a refused save leaves none.
 *
 *   Created: a new record's value in a column (empty columns are left out).
 *   Updated: a column whose value differs. Empty and null are the same value.
 *
 * Values travel as text. Columns in HIDDEN are logged as "(hidden)": that they changed is recorded, not the value.
 */
final class ChangeLog
{
    public const MANUAL = 'Manual';      // a person, through a screen or the command line

    public const AUTOMATIC = 'Automatic'; // the system itself, such as an archive rule

    public const HIDDEN = ['password'];

    public const HIDDEN_VALUE = '(hidden)';

    /** Columns holding true or false (a database may hand them back as 1 and 0): always logged as true / false. */
    public const YES_NO = ['active'];

    /** @param  array<string, mixed>  $values  the record's columns by database name */
    public static function created(string $table, int|string $id, array $values, ?User $user, string $source = self::MANUAL): void
    {
        self::write($table, $id, self::onCreate($values), $user, $source);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after  only these columns are compared
     */
    public static function updated(string $table, int|string $id, array $before, array $after, ?User $user, string $source = self::MANUAL): void
    {
        self::write($table, $id, self::onUpdate($before, $after), $user, $source);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<array{column: string, before: ?string, after: ?string, type: string}>
     */
    public static function onCreate(array $values): array
    {
        $changes = [];
        foreach ($values as $column => $value) {
            $text = self::text($value, $column);
            if ($text !== null) {
                $changes[] = ['column' => $column, 'before' => null, 'after' => self::shown($column, $text), 'type' => 'Created'];
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{column: string, before: ?string, after: ?string, type: string}>
     */
    public static function onUpdate(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $column => $value) {
            $was = self::text($before[$column] ?? null, $column);
            $now = self::text($value, $column);
            if (($was ?? '') === ($now ?? '')) {
                continue;
            }
            $changes[] = ['column' => $column, 'before' => self::shown($column, $was), 'after' => self::shown($column, $now), 'type' => 'Updated'];
        }

        return $changes;
    }

    /** @param  list<array{column: string, before: ?string, after: ?string, type: string}>  $changes */
    private static function write(string $table, int|string $id, array $changes, ?User $user, string $source): void
    {
        if ($changes === []) {
            return;
        }

        $request = request();
        $console = app()->runningInConsole() && ! app()->runningUnitTests();
        $saveId = (string) Str::uuid();
        $at = now()->utc();

        DB::table('audit_changes')->insert(array_map(fn (array $change) => [
            'save_id' => $saveId,
            'occurred_at' => $at,
            'actor_user_id' => $user?->id,
            'actor_username' => $user?->username ?? ($console ? 'console' : null),
            'source' => $source,
            'table_name' => $table,
            'record_id' => (string) $id,
            'column_name' => $change['column'],
            'value_before' => $change['before'],
            'value_after' => $change['after'],
            'change_type' => $change['type'],
            'ip' => $console ? null : $request->ip(),
        ], $changes));
    }

    private static function text(mixed $value, string $column): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (in_array($column, self::YES_NO, true)) {
            $value = (bool) $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private static function shown(string $column, ?string $value): ?string
    {
        return $value !== null && in_array($column, self::HIDDEN, true) ? self::HIDDEN_VALUE : $value;
    }
}
