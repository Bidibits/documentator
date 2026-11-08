<?php

namespace App\Documents;

use App\Exceptions\ApiError;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\ChangeLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The rules that archive old versions by themselves (set on the Archiving panel, run every night and on demand).
 *
 *   idle: a version nobody has viewed or downloaded for AMOUNT UNITs. A version never opened counts from the day it
 *         was uploaded.
 *   old : a version AMOUNT UNITs after a newer version replaced it (0 days: as soon as it is replaced).
 *
 * Neither ever touches a current version, an archived one, or (for "old") one somebody took out of the archive; the
 * idle rule counts a restored version from the day it was restored. That way a restore is not undone the same night.
 */
final class ArchiveRules
{
    public const KINDS = ['idle', 'old'];

    public const UNITS = ['days', 'months', 'years'];

    /** What a rule is when it has never been saved: off. */
    private const DEFAULTS = [
        'idle' => ['amount' => 1, 'unit' => 'years'],
        'old' => ['amount' => 0, 'unit' => 'days'],
    ];

    /** @return array<string, array{kind: string, enabled: bool, amount: int, unit: string}> both rules, by kind */
    public static function all(): array
    {
        $saved = DB::table('archive_rules')->get()->keyBy('kind');
        $rules = [];
        foreach (self::KINDS as $kind) {
            $row = $saved->get($kind);
            $rules[$kind] = [
                'kind' => $kind,
                'enabled' => $row ? (bool) $row->enabled : false,
                'amount' => $row ? (int) $row->amount : self::DEFAULTS[$kind]['amount'],
                'unit' => $row ? $row->unit : self::DEFAULTS[$kind]['unit'],
            ];
        }

        return $rules;
    }

    /** Saves a rule (making it the first time) and logs each column that changed. */
    public static function save(string $kind, bool $enabled, int $amount, string $unit, User $user): array
    {
        $min = $kind === 'old' ? 0 : 1;
        if (! in_array($kind, self::KINDS, true) || ! in_array($unit, self::UNITS, true) || $amount < $min || $amount > 999) {
            throw new ApiError(422, 'invalid', ['field' => in_array($unit, self::UNITS, true) ? 'amount' : 'unit', 'message' => 'rule']);
        }

        DB::transaction(function () use ($kind, $enabled, $amount, $unit, $user) {
            $row = DB::table('archive_rules')->where('kind', $kind)->first();
            $now = now()->utc();
            if ($row === null) {
                $id = DB::table('archive_rules')->insertGetId([
                    'kind' => $kind, 'enabled' => $enabled, 'amount' => $amount, 'unit' => $unit,
                    'updated_by' => $user->id, 'created_at' => $now, 'updated_at' => $now,
                ]);
                ChangeLog::created('archive_rules', $id, ['kind' => $kind, 'enabled' => $enabled, 'amount' => $amount, 'unit' => $unit], $user);

                return;
            }
            $before = ['enabled' => (bool) $row->enabled, 'amount' => (int) $row->amount, 'unit' => $row->unit];
            $after = ['enabled' => $enabled, 'amount' => $amount, 'unit' => $unit];
            DB::table('archive_rules')->where('id', $row->id)->update($after + ['updated_by' => $user->id, 'updated_at' => $now]);
            ChangeLog::updated('archive_rules', $row->id, $before, $after, $user);
        });

        return self::all()[$kind];
    }

    /**
     * The ids of the versions each ON rule would archive now: kind => ids, each version once per rule. The database
     * decides (a few indexed queries), so tens of thousands of old versions cost almost nothing and nothing is loaded
     * into memory but the ids.
     *
     * @return array<string, Collection<int, int>>
     */
    public static function due(): array
    {
        $rules = array_filter(self::all(), fn (array $rule) => $rule['enabled']);

        return [
            'idle' => isset($rules['idle']) ? self::dueIds('idle', $rules['idle']) : collect(),
            'old' => isset($rules['old']) ? self::dueIds('old', $rules['old']) : collect(),
        ];
    }

    /** @return Collection<int, int> */
    private static function dueIds(string $kind, array $rule): Collection
    {
        $cutoff = self::before($rule)->format('Y-m-d H:i:s');   // the log and the tables keep UTC text, which sorts as time
        $query = DB::table('document_versions as v')
            ->join('documents as d', 'd.id', '=', 'v.document_id')
            ->whereColumn('v.id', '!=', 'd.current_version_id')
            ->whereNull('v.archived_at');

        if ($kind === 'idle') {
            // untouched since the cutoff: its latest view, download or restore is no later (a version never touched counts from the day it was uploaded)
            $access = DB::table('document_files as f')->selectRaw('f.version_id, max(f.last_viewed_at) as lv, max(f.last_downloaded_at) as ld')->groupBy('f.version_id');
            $query->leftJoinSub($access, 'a', 'a.version_id', '=', 'v.id')
                ->where(fn ($w) => $w->whereNull('a.lv')->orWhere('a.lv', '<=', $cutoff))
                ->where(fn ($w) => $w->whereNull('a.ld')->orWhere('a.ld', '<=', $cutoff))
                ->where(fn ($w) => $w->whereNull('v.restored_at')->orWhere('v.restored_at', '<=', $cutoff))
                ->where(fn ($w) => $w->whereNotNull('a.lv')->orWhereNotNull('a.ld')->orWhereNotNull('v.restored_at')->orWhere('v.created_at', '<=', $cutoff));
        } else {
            // replaced by a newer version at or before the cutoff, and not taken out of the archive since
            $query->whereNull('v.restored_at')->whereExists(fn ($newer) => $newer->selectRaw('1')->from('document_versions as nv')
                ->whereColumn('nv.document_id', 'v.document_id')->whereColumn('nv.rev_no', '>', 'v.rev_no')->where('nv.created_at', '<=', $cutoff));
        }

        return $query->orderBy('v.id')->pluck('v.id')->map(fn ($id) => (int) $id);
    }

    /**
     * Archives what the rules say is due, one version at a time (a failure leaves that version as it was and the run
     * carries on). Returns what happened. Every run that did something is noted in the activity log.
     *
     * @return array{archived: int, failed: list<array{id: int, code: string, rev: int, error: string}>, due: int}
     */
    public static function run(?User $user, string $source, int $limit = 500): array
    {
        $due = self::due();
        $ids = $due['idle']->merge($due['old'])->unique()->values();

        $archived = 0;
        $failed = [];
        foreach ($ids->take($limit) as $id) {
            $version = DocumentVersion::with('document')->find($id);
            if ($version === null) {
                continue;
            }
            try {
                Archive::archive($version, $user, $source);
                $archived++;
            } catch (ApiError $e) {
                $failed[] = ['id' => $version->id, 'code' => $version->document->code, 'rev' => $version->rev_no, 'error' => $e->getMessage() ?: 'archive_failed'];
                AuditLog::record($user, 'version.archive-failed', 'Ver '.$version->rev_no.' ('.($failed[count($failed) - 1]['error']).')',
                    username: $user ? null : 'system', entityType: 'document', entityId: $version->document->code);
            }
        }

        if ($archived > 0 || $failed !== [] || $user !== null) {
            AuditLog::record($user, 'archive.run', "archived {$archived}, failed ".count($failed).', '.($ids->count() - $limit > 0 ? ($ids->count() - $limit).' left for the next run' : 'none left'),
                username: $user ? null : 'system');
        }

        return ['archived' => $archived, 'failed' => $failed, 'due' => $ids->count()];
    }

    /** The moment a rule's period reaches back to: versions untouched since then or earlier are due. */
    private static function before(array $rule): Carbon
    {
        $now = now()->utc();

        return match ($rule['unit']) {
            'months' => $now->subMonthsNoOverflow($rule['amount']),
            'years' => $now->subYears($rule['amount']),
            default => $now->subDays($rule['amount']),
        };
    }
}
