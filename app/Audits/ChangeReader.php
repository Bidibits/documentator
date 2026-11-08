<?php

namespace App\Audits;

use App\Auth\Access;
use App\Exceptions\ApiError;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads audit_changes back for the Audits screen (the way KVNsys's Audit log screen does): newest first, at most LIMIT,
 * narrowed by table, record, person and days, with names standing in for ids wherever the record is still there.
 */
final class ChangeReader
{
    /** The newest this many changes are shown; the filters find older ones. */
    public const LIMIT = 500;

    /** The tables the log holds changes to, and what a person calls each. */
    public const TABLES = [
        'documents' => 'Documents',
        'document_versions' => 'Versions',
        'document_files' => 'Files',
        'users' => 'Users',
        'archive_rules' => 'Archive rules',
        'departments' => 'Departments',
        'roles' => 'Roles',
    ];

    /** What the screens call a column, where its database name does not say it the same way. */
    private const COLUMNS = [
        'doc_type' => 'Type',
        'grants' => 'Access',
        'department_id' => 'Department',
        'location_note' => 'Physical location',
        'current_version_id' => 'Current version',
        'created_by' => 'Created by',
        'updated_by' => 'Updated by',
        'document_id' => 'Document',
        'version_id' => 'Version',
        'rev_no' => 'Version number',
        'original_name' => 'File name',
        'mime' => 'File type',
        'size' => 'Size (bytes)',
        'sha256' => 'SHA-256',
        'sort_order' => 'Order',
        'password' => 'Password',
        'auth_source' => 'Sign-in source',
        'external_id' => 'External id',
        'password_changed_at' => 'Password changed',
        'archived_at' => 'Archived on',
        'archived_by' => 'Archived by',
        'restored_at' => 'Taken out of archive',
        'enabled' => 'On',
        'role' => 'Role',
        'must_change_password' => 'Must change password',
        'amount' => 'Number',
        'unit' => 'Unit',
        'kind' => 'Rule',
    ];

    /** Columns that hold a person's id, a document's id or a version's id: the log shows the name instead. */
    private const PEOPLE = ['created_by', 'updated_by', 'archived_by'];

    /** Columns holding true or false. */
    private const YES_NO = ['active', 'enabled', 'must_change_password'];

    /**
     * @param  array<string, mixed>  $typed  table, record, person, from, to as typed and picked (empty for any)
     * @return array<string, mixed>
     */
    public static function search(array $typed): array
    {
        $filter = self::check($typed);

        $query = DB::table('audit_changes')->orderByDesc('id');
        if ($filter['table']) {
            $query->where('table_name', $filter['table']);
        }
        if ($filter['record']) {
            $query->where('record_id', $filter['record']);
        }
        if ($filter['person'] === 'console') {
            $query->whereNull('actor_user_id');
        } elseif ($filter['person']) {
            $query->where('actor_user_id', $filter['person']);
        }
        if ($filter['from']) {
            $query->where('occurred_at', '>=', $filter['from']);
        }
        if ($filter['to']) {
            $query->where('occurred_at', '<', $filter['to']);
        }

        $rows = $query->limit(self::LIMIT + 1)->get();
        $more = $rows->count() > self::LIMIT;
        // newest save first, but the columns of one save in the order they were written, so each save reads top to bottom
        $rows = $rows->take(self::LIMIT)->groupBy('save_id')->flatMap(fn ($save) => $save->reverse()->values())->values();

        $names = self::names($rows);

        return [
            'entries' => $rows->map(fn ($r) => self::entry($r, $names))->values()->all(),
            'more' => $more,
            'people' => self::people(),
            'tables' => collect(self::TABLES)->map(fn ($label, $table) => ['table' => $table, 'label' => $label])->values()->all(),
        ];
    }

    /** @return array{table: ?string, record: ?string, person: ?string, from: ?string, to: ?string} */
    private static function check(array $typed): array
    {
        $text = fn (string $key) => trim((string) ($typed[$key] ?? ''));

        $table = $text('table');
        if ($table !== '' && ! isset(self::TABLES[$table])) {
            throw new ApiError(422, 'invalid', ['field' => 'table', 'message' => 'unknown']);
        }
        $record = $text('record');
        if ($record !== '' && ! ctype_digit($record)) {
            throw new ApiError(422, 'invalid', ['field' => 'record', 'message' => 'unknown']);
        }
        $person = $text('person');
        if ($person !== '' && $person !== 'console' && ! ctype_digit($person)) {
            throw new ApiError(422, 'invalid', ['field' => 'person', 'message' => 'unknown']);
        }

        // a day is a day by the office's clock; the log keeps UTC
        $zone = config('documentator.timezone');
        $day = function (string $key, bool $end) use ($text, $zone): ?string {
            $value = $text($key);
            if ($value === '') {
                return null;
            }
            $date = Carbon::createFromFormat('!Y-m-d', $value, $zone);
            if ($date === false || $date->format('Y-m-d') !== $value) {
                throw new ApiError(422, 'invalid', ['field' => $key, 'message' => 'date']);
            }

            return ($end ? $date->addDay() : $date)->utc()->format('Y-m-d H:i:s');
        };
        $from = $day('from', false);
        $to = $day('to', true);
        if ($from !== null && $to !== null && $from >= $to) {
            throw new ApiError(422, 'invalid', ['field' => 'to', 'message' => 'order']);
        }

        return ['table' => $table ?: null, 'record' => $record ?: null, 'person' => $person ?: null, 'from' => $from, 'to' => $to];
    }

    /** Everyone who has made a change, by name (the console, when no one was signed in). */
    private static function people(): array
    {
        $people = DB::table('audit_changes as c')->leftJoin('users as u', 'u.id', '=', 'c.actor_user_id')
            ->whereNotNull('c.actor_user_id')->groupBy('c.actor_user_id', 'u.name', 'u.username')
            ->orderBy('u.name')->get(['c.actor_user_id as id', 'u.name', 'u.username'])
            ->map(fn ($p) => ['id' => (string) $p->id, 'name' => $p->name ?: $p->username ?: 'Account '.$p->id])->all();

        if (DB::table('audit_changes')->whereNull('actor_user_id')->exists()) {
            $people[] = ['id' => 'console', 'name' => 'The command line or the system'];
        }

        return $people;
    }

    /**
     * What the ids in these rows stand for now: records by table, plus the people, documents and versions that
     * created_by, document_id, version_id and current_version_id point at.
     *
     * @return array{records: array<string, array<string, string>>, users: array<string, string>, documents: array<string, string>, versions: array<string, string>}
     */
    private static function names($rows): array
    {
        $ids = fn (string $table) => $rows->where('table_name', $table)->pluck('record_id')->unique()->all();
        $refs = fn (string $column) => $rows->where('column_name', $column)->flatMap(fn ($r) => [$r->value_before, $r->value_after])->filter()->unique()->all();

        $userIds = array_merge($ids('users'), $refs('created_by'), $refs('updated_by'), $refs('archived_by'), $rows->pluck('actor_user_id')->filter()->all());
        $versionIds = array_merge($ids('document_versions'), $refs('version_id'), $refs('current_version_id'));
        $fileIds = $ids('document_files');
        $documentIds = array_merge($ids('documents'), $refs('document_id'));

        // a file's or version's own label names its document, so those documents are needed too
        $versionRows = $versionIds ? DB::table('document_versions')->whereIn('id', $versionIds)->get(['id', 'document_id', 'rev_no']) : collect();
        $fileRows = $fileIds ? DB::table('document_files')->whereIn('id', $fileIds)->get(['id', 'original_name', 'version_id']) : collect();
        $documentIds = array_merge($documentIds, $versionRows->pluck('document_id')->all());

        $departments = DB::table('departments')->whereIn('id', array_unique(array_merge($ids('departments'), $refs('department_id'))))->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all();
        $roleNames = DB::table('roles')->whereIn('id', $ids('roles'))->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all();
        $ruleIds = $ids('archive_rules');
        $rules = $ruleIds ? DB::table('archive_rules')->whereIn('id', $ruleIds)->pluck('kind', 'id')
            ->mapWithKeys(fn ($kind, $id) => [(string) $id => $kind === 'idle' ? 'Not opened for a while' : 'Replaced by a newer version'])->all() : [];
        $codes = $documentIds ? DB::table('documents')->whereIn('id', array_unique($documentIds))->pluck('code', 'id')->all() : [];
        $users = $userIds ? DB::table('users')->whereIn('id', array_unique($userIds))->get(['id', 'name', 'username'])
            ->mapWithKeys(fn ($u) => [(string) $u->id => $u->name ?: $u->username])->all() : [];
        $versions = $versionRows->mapWithKeys(fn ($v) => [(string) $v->id => ($codes[$v->document_id] ?? 'Document '.$v->document_id).' · Ver '.$v->rev_no])->all();

        return [
            'records' => [
                'documents' => collect($codes)->mapWithKeys(fn ($code, $id) => [(string) $id => $code])->all(),
                'document_versions' => $versions,
                'document_files' => $fileRows->mapWithKeys(fn ($f) => [(string) $f->id => $f->original_name])->all(),
                'users' => $users,
                'archive_rules' => $rules,
                'departments' => $departments,
                'roles' => $roleNames,
            ],
            'users' => $users,
            'rules' => $rules,
            'departments' => $departments,
            'documents' => collect($codes)->mapWithKeys(fn ($code, $id) => [(string) $id => $code])->all(),
            'versions' => $versions,
        ];
    }

    /** @param  array<string, mixed>  $names */
    private static function entry(object $r, array $names): array
    {
        return [
            'id' => $r->id,
            'saveId' => $r->save_id,
            'at' => Carbon::parse($r->occurred_at, 'UTC')->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'table' => $r->table_name,
            'tableLabel' => self::TABLES[$r->table_name] ?? $r->table_name,
            'recordId' => $r->record_id,
            'record' => $names['records'][$r->table_name][$r->record_id] ?? null,
            'column' => $r->column_name,
            'columnLabel' => self::COLUMNS[$r->column_name] ?? ucfirst(str_replace('_', ' ', $r->column_name)),
            'before' => self::shown($r->column_name, $r->value_before, $names),
            'after' => self::shown($r->column_name, $r->value_after, $names),
            'change' => $r->change_type,
            'source' => $r->source,
            'by' => $r->actor_user_id ? ($names['users'][(string) $r->actor_user_id] ?? $r->actor_username)
                : match ($r->actor_username) {
                    'console' => 'The command line',
                    'system' => 'The system (archive rules)',
                    default => '',
                },
            'where' => (string) $r->ip,
        ];
    }

    /** @param  array<string, mixed>  $names */
    private static function shown(string $column, ?string $value, array $names): ?string
    {
        if ($value === null) {
            return null;
        }
        if (in_array($column, self::PEOPLE, true)) {
            return $names['users'][$value] ?? $value;
        }

        return match (true) {
            $column === 'document_id' => $names['documents'][$value] ?? $value,
            $column === 'department_id' => $names['departments'][$value] ?? $value,
            $column === 'version_id', $column === 'current_version_id' => $names['versions'][$value] ?? $value,
            $column === 'role' => Access::label($value),
            in_array($column, self::YES_NO, true) => $value === 'true' ? 'Yes' : ($value === 'false' ? 'No' : $value),
            default => $value,
        };
    }
}
