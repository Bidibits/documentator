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
     * The versions each ON rule would archive now: kind => versions, each version once per rule.
     *
     * @return array<string, Collection<int, DocumentVersion>>
     */
    public static function due(?int $limit = null): array
    {
        $rules = array_filter(self::all(), fn (array $rule) => $rule['enabled']);
        $due = ['idle' => collect(), 'old' => collect()];
        if ($rules === []) {
            return $due;
        }

        $candidates = DocumentVersion::query()
            ->join('documents as d', 'd.id', '=', 'document_versions.document_id')
            ->whereColumn('document_versions.id', '!=', 'd.current_version_id')
            ->whereNull('document_versions.archived_at')
            ->select('document_versions.*')
            ->addSelect(['became_old_at' => DB::table('document_versions as nv')
                ->whereColumn('nv.document_id', 'document_versions.document_id')
                ->whereColumn('nv.rev_no', '>', 'document_versions.rev_no')
                ->selectRaw('min(nv.created_at)')])
            ->withMax('files as last_viewed_at', 'last_viewed_at')
            ->withMax('files as last_downloaded_at', 'last_downloaded_at')
            ->orderBy('document_versions.id')
            ->get();

        foreach ($candidates as $version) {
            $attributes = $version->getAttributes();
            if (isset($rules['idle'])) {
                $touched = collect([$attributes['last_viewed_at'] ?? null, $attributes['last_downloaded_at'] ?? null, $version->restored_at?->format('Y-m-d H:i:s')])
                    ->filter()->max() ?? $version->created_at->format('Y-m-d H:i:s');
                if (Carbon::parse($touched, 'UTC')->lte(self::before($rules['idle']))) {
                    $due['idle']->push($version);
                }
            }
            if (isset($rules['old']) && $version->restored_at === null && ($attributes['became_old_at'] ?? null) !== null
                && Carbon::parse($attributes['became_old_at'], 'UTC')->lte(self::before($rules['old']))) {
                $due['old']->push($version);
            }
        }

        return $due;
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
        $versions = $due['idle']->merge($due['old'])->unique('id')->values();
        $byRule = fn (DocumentVersion $v) => $due['old']->contains('id', $v->id) ? 'old' : 'idle';

        $archived = 0;
        $failed = [];
        foreach ($versions->take($limit) as $version) {
            $version->load('document');
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
            AuditLog::record($user, 'archive.run', "archived {$archived}, failed ".count($failed).', '.($versions->count() - $limit > 0 ? ($versions->count() - $limit).' left for the next run' : 'none left'),
                username: $user ? null : 'system');
        }

        return ['archived' => $archived, 'failed' => $failed, 'due' => $versions->count()];
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
