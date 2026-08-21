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
    public function seedFor(GameType $type, CarbonInterface $date): int
    {
        return crc32("{$type->value}-{$date->toDateString()}");
    }
}
