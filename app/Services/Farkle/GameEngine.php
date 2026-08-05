<?php

namespace App\Services\Farkle;

class GameEngine
{
    public function __construct(
        private readonly Scorer $scorer,
    ) {}

    /**
     * @param  list<int>  $values  the selected dice (a subset of one roll)
     * @return array{score: int, valid: bool}
     */
    public function scoreSelection(array $values): array
    {
        return $this->scorer->scoreSelection($values);
    }

    /**
     * @param  list<int>  $values  a full roll, not a selection
     */
    public function hasAnyScoringDice(array $values): bool
    {
        return $this->scorer->hasAnyScoringDice($values);
    }

    /**
     * Whether banking `$projectedTurnScore` right now would count at all --
     * always true once a player has already gotten on the board once
     * before (`$hasQualified`), otherwise only once this turn alone
     * reaches the minimum entry score on its own.
     */
    public function meetsMinimumEntry(bool $hasQualified, int $projectedTurnScore): bool
    {
        return $hasQualified || $projectedTurnScore >= Scorer::MINIMUM_ENTRY_SCORE;
    }
}
