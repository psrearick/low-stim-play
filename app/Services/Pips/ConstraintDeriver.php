<?php

namespace App\Services\Pips;

use Random\Randomizer;

/**
 * Assigns each region a constraint chosen from whichever types are actually
 * true of that region's real pip values (docs/specs/pips.md section 3) --
 * a region can satisfy more than one type at once (e.g. 2,2,2 satisfies
 * both "all equal" and "sum=6"), so a weighted random roll picks among the
 * eligible ones for variety rather than always preferring the same type.
 */
class ConstraintDeriver
{
    /**
     * How far below/above a region's actual sum a "greater than"/"less
     * than" threshold is drawn from -- keeps the shown number close enough
     * to the true sum to look like a real constraint rather than a distant,
     * uninformative bound.
     */
    private const THRESHOLD_MAX_DELTA = 4;

    /**
     * @param  array<int, list<int>>  $regions
     * @param  array<int, int>  $cellValues
     * @return array<int, array{type: string, value: int|null}>
     */
    public function derive(array $regions, array $cellValues, Randomizer $randomizer): array
    {
        $constraints = [];

        foreach ($regions as $regionId => $cells) {
            $values = array_map(fn (int $cell) => $cellValues[$cell], $cells);
            $constraints[$regionId] = $this->deriveForRegion($values, $randomizer);
        }

        return $constraints;
    }

    /**
     * @param  list<int>  $values
     * @return array{type: string, value: int|null}
     */
    private function deriveForRegion(array $values, Randomizer $randomizer): array
    {
        // A single-cell region: "all equal"/"all different" are vacuously
        // true of one value and would communicate nothing to a player, so
        // only "sum" (the fixed value itself) or "none" are ever offered.
        if (count($values) === 1) {
            return $this->weightedPick($randomizer, [
                'sum' => [80, $values[0]],
                'none' => [20, null],
            ]);
        }

        $size = count($values);
        $sum = array_sum($values);
        $distinct = count(array_unique($values));

        // Weighted toward 'sum' and 'equal' -- both pin down a region's
        // exact value set (an 'equal' region only ever admits a domino
        // fully inside it if that domino is already a double, since both
        // its cells must already share the region's one common value),
        // unlike 'different'/'greater'/'less'/'none', which only rule out
        // or bound a small slice of the possible value combinations. This
        // isn't just a flavor preference: SolutionSolver's uniqueness check
        // (docs/DEVLOG.md's Pips entry) found that a board dominated by the
        // looser types is very rarely uniquely solvable at all, since any
        // two dominoes a loose-enough pair of regions can't tell apart
        // become freely interchangeable -- biasing generation toward the
        // tighter types up front is what makes PuzzleGenerator's retry loop
        // actually converge in practice rather than exhausting its attempt
        // budget.
        $weighted = ['sum' => [45, $sum]];

        if ($distinct === 1) {
            $weighted['equal'] = [30, null];
        } elseif ($distinct === $size) {
            $weighted['different'] = [10, null];
        }

        // Only offered when the true sum can support a strictly-lower
        // threshold that stays non-negative (a region of all zeros has no
        // truthful "greater than N>=0" reading).
        if ($sum >= 1) {
            $delta = $randomizer->getInt(1, min(self::THRESHOLD_MAX_DELTA, $sum));
            $weighted['greater'] = [12, $sum - $delta];
        }

        $delta = $randomizer->getInt(1, self::THRESHOLD_MAX_DELTA);
        $weighted['less'] = [10, $sum + $delta];

        $weighted['none'] = [5, null];

        return $this->weightedPick($randomizer, $weighted);
    }

    /**
     * @param  array<string, array{0: int, 1: int|null}>  $weighted
     * @return array{type: string, value: int|null}
     */
    private function weightedPick(Randomizer $randomizer, array $weighted): array
    {
        $total = array_sum(array_map(fn (array $entry) => $entry[0], $weighted));
        $roll = $randomizer->getInt(1, $total);

        $cumulative = 0;

        foreach ($weighted as $type => [$weight, $value]) {
            $cumulative += $weight;

            if ($roll <= $cumulative) {
                return ['type' => $type, 'value' => $value];
            }
        }

        // Unreachable given $roll <= $total; kept only so this method has a total return type.
        $lastType = array_key_last($weighted);

        return ['type' => $lastType, 'value' => $weighted[$lastType][1]];
    }
}
