<?php

namespace App\Services\Yahtzee;

use App\Enums\YahtzeeCategory;

class Scorer
{
    public const UPPER_BONUS_THRESHOLD = 63;

    public const UPPER_BONUS = 35;

    public const YAHTZEE_BONUS_POINTS = 100;

    /**
     * Score a single category for a completed roll (all 5 dice).
     *
     * `$jokerBonusEligible` is true only when the Joker rule applies: the
     * roll is a Yahtzee (five of a kind), the player's Yahtzee box is
     * already filled, and — for Full House / Small Straight / Large
     * Straight specifically — the matching Upper Section box is also
     * filled. The caller (`GameEngine`) works that context out, since it
     * needs the player's other scores to do so; this method just applies
     * the fixed value once told to.
     *
     * @param  list<int>  $diceValues  exactly 5 die values (1-6)
     */
    public function score(YahtzeeCategory $category, array $diceValues, bool $jokerBonusEligible = false): int
    {
        if (count($diceValues) < 5) {
            return 0;
        }

        $counts = $this->countsOf($diceValues);
        $sum = array_sum($diceValues);

        if ($category->face() !== null) {
            return $counts[$category->face()] * $category->face();
        }

        return match ($category) {
            YahtzeeCategory::ThreeOfAKind => max($counts) >= 3 ? $sum : 0,
            YahtzeeCategory::FourOfAKind => max($counts) >= 4 ? $sum : 0,
            YahtzeeCategory::FullHouse => $jokerBonusEligible ? 25 : ($this->isFullHouse($counts) ? 25 : 0),
            YahtzeeCategory::SmallStraight => $jokerBonusEligible ? 30 : ($this->hasRun($diceValues, 4) ? 30 : 0),
            YahtzeeCategory::LargeStraight => $jokerBonusEligible ? 40 : ($this->hasRun($diceValues, 5) ? 40 : 0),
            YahtzeeCategory::Yahtzee => max($counts) === 5 ? 50 : 0,
            YahtzeeCategory::Chance => $sum,
            default => 0,
        };
    }

    /**
     * @param  list<int>  $diceValues
     */
    public function isYahtzeeRoll(array $diceValues): bool
    {
        return count($diceValues) === 5 && max($this->countsOf($diceValues)) === 5;
    }

    /**
     * The Upper Section category a Yahtzee roll matches, e.g. five 4s -> Fours.
     *
     * @param  list<int>  $diceValues
     */
    public function matchingUpperCategory(array $diceValues): ?YahtzeeCategory
    {
        if ($diceValues === []) {
            return null;
        }

        return YahtzeeCategory::forFace($diceValues[0]);
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

    /**
     * @param  array<int, int>  $counts
     */
    private function isFullHouse(array $counts): bool
    {
        return in_array(3, $counts, true) && in_array(2, $counts, true);
    }

    /**
     * @param  list<int>  $values
     */
    private function hasRun(array $values, int $length): bool
    {
        $set = array_unique($values);

        for ($start = 1; $start + $length - 1 <= 6; $start++) {
            $run = range($start, $start + $length - 1);

            if (count(array_intersect($run, $set)) === $length) {
                return true;
            }
        }

        return false;
    }
}
