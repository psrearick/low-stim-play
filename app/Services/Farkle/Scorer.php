<?php

namespace App\Services\Farkle;

class Scorer
{
    /**
     * A turn's banked total must reach this on its own before a player is
     * "on the board" -- see docs/specs/farkle.md's "Win condition"
     * section. Every turn after that qualifies regardless of size.
     */
    public const MINIMUM_ENTRY_SCORE = 500;

    /**
     * Scores a chosen subset of a roll (the dice the player is setting
     * aside this roll), using the standard variant from
     * docs/specs/farkle.md: single 1s/5s score individually (100/50 each);
     * three or more of the same face score as a group, doubling per extra
     * die beyond three (three 1s = 1000, four = 2000, five = 4000, six =
     * 8000; three of any other face `n` = `n * 100`, doubling the same
     * way). No straight or three-pair bonus in this ruleset.
     *
     * `valid` is false if *any* selected die doesn't contribute to the
     * score at all (e.g. a lone 3, or two 2s with nothing else) -- Farkle
     * only lets a player set aside dice that actually score, so a
     * selection containing a non-scoring die is simply illegal, not "worth
     * less."
     *
     * @param  list<int>  $values  the selected dice (a subset of one roll, not the whole roll)
     * @return array{score: int, valid: bool}
     */
    public function scoreSelection(array $values): array
    {
        if ($values === []) {
            return ['score' => 0, 'valid' => false];
        }

        $counts = $this->countsOf($values);
        $score = 0;

        foreach ($counts as $face => $count) {
            if ($count < 3) {
                continue;
            }

            $base = $face === 1 ? 1000 : $face * 100;
            $multiplier = 2 ** ($count - 3);
            $score += $base * $multiplier;
            $counts[$face] = 0;
        }

        // Whatever's left after removing complete of-a-kind groups: single
        // 1s and 5s still score individually, but a leftover 1 or 2 of any
        // other face (2/3/4/6) contributes nothing and invalidates the
        // whole selection -- those dice were selected but don't score.
        foreach ($counts as $face => $count) {
            if ($count === 0) {
                continue;
            }

            if ($face === 1) {
                $score += $count * 100;
            } elseif ($face === 5) {
                $score += $count * 50;
            } else {
                return ['score' => 0, 'valid' => false];
            }
        }

        return ['score' => $score, 'valid' => true];
    }

    /**
     * Whether a freshly rolled hand contains *any* scoring dice at all --
     * false means an immediate Farkle (the turn busts, whatever was
     * accumulated this turn is lost). This is always true the instant
     * there's a single 1 or 5 in the roll, or three-of-a-kind of anything,
     * regardless of how those dice end up actually being grouped/selected
     * afterward.
     *
     * @param  list<int>  $values  a full roll, not a selection
     */
    public function hasAnyScoringDice(array $values): bool
    {
        $counts = $this->countsOf($values);

        return $counts[1] > 0 || $counts[5] > 0 || max($counts) >= 3;
    }

    /**
     * @param  list<int>  $values
     * @return array<int, int> count of each face 1-6, indexed by face
     */
    private function countsOf(array $values): array
    {
        $counts = array_fill(1, 6, 0);

        foreach ($values as $value) {
            $counts[$value]++;
        }

        return $counts;
    }
}
