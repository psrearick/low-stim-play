<?php

namespace App\Services\Yahtzee;

use App\Enums\YahtzeeCategory;

class GameEngine
{
    public function __construct(
        private readonly Scorer $scorer,
    ) {}

    /**
     * Score a category for a completed roll, given the player's scores so
     * far (needed for the Joker rule and the Yahtzee bonus chip).
     *
     * @param  list<int>  $diceValues
     * @param  array<string, int|null>  $currentScores  keyed by YahtzeeCategory value
     * @return array{score: int, yahtzeeBonusAwarded: bool}
     */
    public function scoreCategory(YahtzeeCategory $category, array $diceValues, array $currentScores): array
    {
        $yahtzeeScored = $currentScores[YahtzeeCategory::Yahtzee->value] ?? null;
        $isYahtzeeRoll = $this->scorer->isYahtzeeRoll($diceValues);

        // Awarded only for an *extra* Yahtzee, once the box already shows
        // 50 - scoring the Yahtzee box itself for the first time doesn't
        // count, and neither does a "forced zero" Yahtzee box.
        $yahtzeeBonusAwarded = $isYahtzeeRoll && $yahtzeeScored === 50;

        // The Joker rule activates once the Yahtzee box has been scored at
        // all (50 or a forced 0), on any later Yahtzee roll.
        $jokerActive = $isYahtzeeRoll && $yahtzeeScored !== null;
        $jokerBonusEligible = false;

        if ($jokerActive && in_array($category, [YahtzeeCategory::FullHouse, YahtzeeCategory::SmallStraight, YahtzeeCategory::LargeStraight], true)) {
            $matchingUpper = $this->scorer->matchingUpperCategory($diceValues);
            $jokerBonusEligible = $matchingUpper !== null && ($currentScores[$matchingUpper->value] ?? null) !== null;
        }

        return [
            'score' => $this->scorer->score($category, $diceValues, $jokerBonusEligible),
            'yahtzeeBonusAwarded' => $yahtzeeBonusAwarded,
        ];
    }

    /**
     * @param  array<string, int|null>  $scores
     */
    public function upperSubtotal(array $scores): int
    {
        $total = 0;

        foreach (YahtzeeCategory::upper() as $category) {
            $total += $scores[$category->value] ?? 0;
        }

        return $total;
    }

    /**
     * @param  array<string, int|null>  $scores
     */
    public function upperBonus(array $scores): int
    {
        return $this->upperSubtotal($scores) >= Scorer::UPPER_BONUS_THRESHOLD ? Scorer::UPPER_BONUS : 0;
    }

    public function yahtzeeBonusPoints(int $yahtzeeBonusCount): int
    {
        return $yahtzeeBonusCount * Scorer::YAHTZEE_BONUS_POINTS;
    }

    /**
     * @param  array<string, int|null>  $scores
     */
    public function lowerSubtotal(array $scores): int
    {
        $total = 0;

        foreach (YahtzeeCategory::lower() as $category) {
            $total += $scores[$category->value] ?? 0;
        }

        return $total;
    }

    /**
     * @param  array<string, int|null>  $scores
     */
    public function totalScore(array $scores, int $yahtzeeBonusCount): int
    {
        return $this->upperSubtotal($scores)
            + $this->upperBonus($scores)
            + $this->lowerSubtotal($scores)
            + $this->yahtzeeBonusPoints($yahtzeeBonusCount);
    }

    /**
     * @param  array<string, int|null>  $scores
     */
    public function allCategoriesFilled(array $scores): bool
    {
        foreach (YahtzeeCategory::all() as $category) {
            if (($scores[$category->value] ?? null) === null) {
                return false;
            }
        }

        return true;
    }
}
