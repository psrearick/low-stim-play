<?php

namespace App\Services\Pips;

/**
 * Duration-based scoring (docs/specs/pips.md section 4) -- unlike every
 * other game in this app, Pips has no natural point total to reward, so
 * score is a simple decreasing function of solve time instead, the way
 * Wordle's own scoring rewards fewer guesses. The exact formula/scale is a
 * tuning detail, not a blocking design decision, per the spec's own
 * allowance -- reaches 0 at 2000 seconds (~33 minutes), a generous ceiling
 * for a full board.
 */
class Scorer
{
    private const MAX_SCORE = 1000;

    public function scoreFor(int $durationSeconds): int
    {
        return max(0, self::MAX_SCORE - intdiv($durationSeconds, 2));
    }
}
