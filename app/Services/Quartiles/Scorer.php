<?php

namespace App\Services\Quartiles;

class Scorer
{
    /**
     * Awarded once all 5 quartile groups have been found, on top of
     * whatever the individual 4-tile words themselves already scored.
     */
    public const QUARTILE_BONUS = 40;

    /**
     * Points for a word formed from the given number of tiles: 1/2/4/8 for
     * 1/2/3/4 tiles, matching docs/specs/quartiles.md's scoring table.
     */
    public function pointsFor(int $tileCount): int
    {
        return match ($tileCount) {
            1 => 1,
            2 => 2,
            3 => 4,
            4 => 8,
            default => 0,
        };
    }
}
