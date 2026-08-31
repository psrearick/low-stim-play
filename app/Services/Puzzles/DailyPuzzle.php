<?php

namespace App\Services\Puzzles;

use App\Enums\GameType;
use Carbon\CarbonInterface;

/**
 * Derives a deterministic puzzle seed for a given game/date pair -- no
 * `daily_puzzles` table is needed since every puzzle generator already
 * accepts a plain `int $seed` (see `BoardGenerator`/`PuzzleGenerator`), and
 * `crc32()` of a `"{type}-{date}"` string is stable, non-negative, and cheap
 * to recompute anywhere (mount(), the archive page, tests) without ever
 * persisting "today's puzzle" as its own row.
 */
class DailyPuzzle
{
    /**
     * $variant lets a single game/date pair resolve to more than one
     * distinct daily seed -- added for Pips' three difficulty tiers
     * (docs/specs/pips.md section 3), which each need their own daily
     * puzzle sharing the same date. Defaults to null so every existing call
     * site (`seedFor($type, $date)`) hashes exactly the same string as
     * before, byte-for-byte.
     */
    public function seedFor(GameType $type, CarbonInterface $date, ?string $variant = null): int
    {
        $key = "{$type->value}-{$date->toDateString()}";

        if ($variant !== null) {
            $key .= "-{$variant}";
        }

        return crc32($key);
    }
}
