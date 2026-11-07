<?php

namespace App\Documents;

use App\Models\Document;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The code a new document is offered: PREFIX-YY-MM-DD-NN, the day being today in the office's time zone and NN the
 * next free number that day (01, 02, ... 99, 100). It is only a suggestion: the person may keep it, change it or
 * replace it with any text. The unique index on documents.code, not this class, is what stops two documents sharing
 * a code, so two people offered the same number simply get a "taken" answer for the second one.
 */
final class CodeSuggestion
{
    public static function next(?CarbonInterface $now = null): string
    {
        $head = self::head($now);

        $highest = 0;
        foreach (self::taken($head) as $code) {
            $tail = substr($code, strlen($head));
            if ($tail !== '' && ctype_digit($tail)) {
                $highest = max($highest, (int) $tail);
            }
        }

        return $head.str_pad((string) ($highest + 1), 2, '0', STR_PAD_LEFT);
    }

    /** Whether the text is shaped like a suggestion, so a "taken" answer can offer the next one. */
    public static function looksSuggested(string $code): bool
    {
        $prefix = preg_quote((string) config('documentator.code_prefix'), '/');

        return preg_match('/^'.$prefix.'-\d{2}-\d{2}-\d{2}-\d{2,}$/i', $code) === 1;
    }

    private static function head(?CarbonInterface $now): string
    {
        $now = ($now ?? now())->copy()->setTimezone(config('documentator.timezone'));

        return config('documentator.code_prefix').$now->format('-y-m-d-');
    }

    /** @return iterable<string> */
    private static function taken(string $head): iterable
    {
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $head).'%';

        return DB::table((new Document)->getTable())
            ->whereRaw("code LIKE ? ESCAPE '\\'", [$like])
            ->pluck('code');
    }
}
