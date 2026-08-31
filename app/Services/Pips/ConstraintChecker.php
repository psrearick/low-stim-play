<?php

namespace App\Services\Pips;

use InvalidArgumentException;

/**
 * Given a region's constraint and whichever of its cells are currently
 * filled, reports whether it's already satisfied, already broken, or not
 * yet determinable -- shared by pages::pips.play's live per-region feedback
 * and SolutionSolver's search pruning (docs/specs/pips.md section 4), so the
 * satisfied/broken/unknown semantics only exist in one place. Region sizes
 * are tiny (at most a handful of cells), so this recomputes from the plain
 * filled-values list every call rather than hand-maintaining incremental
 * sums -- simpler, one source of truth, and the cost is negligible next to
 * SolutionSolver's actual bottleneck (search breadth).
 */
class ConstraintChecker
{
    /**
     * @param  array{type: string, value: int|null}  $constraint
     * @param  list<int>  $filledValues  values already placed in this region's cells
     * @param  int  $cellsRemaining  how many of this region's cells are still empty
     * @param  int  $maxPip  the puzzle's own difficulty ceiling (0..maxPip per cell)
     */
    public function check(array $constraint, array $filledValues, int $cellsRemaining, int $maxPip): ConstraintStatus
    {
        return match ($constraint['type']) {
            'none' => ConstraintStatus::Satisfied,
            'sum' => $this->checkSum($filledValues, $cellsRemaining, $constraint['value'], $maxPip),
            'equal' => $this->checkEqual($filledValues, $cellsRemaining),
            'different' => $this->checkDifferent($filledValues, $cellsRemaining),
            'greater' => $this->checkGreater($filledValues, $cellsRemaining, $constraint['value'], $maxPip),
            'less' => $this->checkLess($filledValues, $cellsRemaining, $constraint['value'], $maxPip),
            default => throw new InvalidArgumentException("Unknown Pips constraint type [{$constraint['type']}]."),
        };
    }

    /**
     * @param  list<int>  $values
     */
    private function checkSum(array $values, int $remaining, int $target, int $maxPip): ConstraintStatus
    {
        $sum = array_sum($values);

        if ($sum > $target || $sum + $maxPip * $remaining < $target) {
            return ConstraintStatus::Broken;
        }

        return $remaining === 0 ? ConstraintStatus::Satisfied : ConstraintStatus::Unknown;
    }

    /**
     * @param  list<int>  $values
     */
    private function checkEqual(array $values, int $remaining): ConstraintStatus
    {
        if (count(array_unique($values)) > 1) {
            return ConstraintStatus::Broken;
        }

        return $remaining === 0 ? ConstraintStatus::Satisfied : ConstraintStatus::Unknown;
    }

    /**
     * @param  list<int>  $values
     */
    private function checkDifferent(array $values, int $remaining): ConstraintStatus
    {
        if (count(array_unique($values)) < count($values)) {
            return ConstraintStatus::Broken;
        }

        return $remaining === 0 ? ConstraintStatus::Satisfied : ConstraintStatus::Unknown;
    }

    /**
     * "Greater than $threshold" -- can resolve early in either direction
     * since a running sum only ever grows as more cells fill in.
     *
     * @param  list<int>  $values
     */
    private function checkGreater(array $values, int $remaining, int $threshold, int $maxPip): ConstraintStatus
    {
        $sum = array_sum($values);

        if ($sum > $threshold) {
            return ConstraintStatus::Satisfied;
        }

        if ($sum + $maxPip * $remaining <= $threshold) {
            return ConstraintStatus::Broken;
        }

        return ConstraintStatus::Unknown;
    }

    /**
     * "Less than $threshold" -- the mirror image of checkGreater().
     *
     * @param  list<int>  $values
     */
    private function checkLess(array $values, int $remaining, int $threshold, int $maxPip): ConstraintStatus
    {
        $sum = array_sum($values);

        if ($sum >= $threshold) {
            return ConstraintStatus::Broken;
        }

        if ($sum + $maxPip * $remaining < $threshold) {
            return ConstraintStatus::Satisfied;
        }

        return ConstraintStatus::Unknown;
    }
}
