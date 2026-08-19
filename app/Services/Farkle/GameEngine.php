<?php

namespace App\Services\Farkle;

class GameEngine
{
    public function __construct(
        private readonly Scorer $scorer,
    ) {}

    /**
     * @param  list<int>  $values  the selected dice (a subset of one roll)
     * @param  list<bool>  $spicy  Spicy variant flags parallel to $values (docs/specs/farkle.md §6.2); omit for a non-Spicy game
     * @return array{score: int, valid: bool}
     */
    public function scoreSelection(array $values, array $spicy = []): array
    {
        return $this->scorer->scoreSelection($values, $spicy);
    }

    /**
     * @param  list<int>  $values  a full roll, not a selection
     */
    public function hasAnyScoringDice(array $values): bool
    {
        return $this->scorer->hasAnyScoringDice($values);
    }

    /**
     * @param  list<int>  $values  a full roll, not a selection
     * @return list<int> indices into $values
     */
    public function nonScoringIndices(array $values): array
    {
        return $this->scorer->nonScoringIndices($values);
    }

    /**
     * @param  list<int>  $values  a full roll, not a manual selection
     * @param  list<bool>  $spicy  Spicy variant flags parallel to $values (docs/specs/farkle.md §6.2); omit for a non-Spicy game
     */
    public function scoreMaximal(array $values, array $spicy = []): int
    {
        return $this->scorer->scoreMaximal($values, $spicy);
    }

    /**
     * The Spicy variant's instant-win condition (docs/specs/farkle.md
     * §6.4): every one of a full 6-die roll landed on its own spicy
     * label. Only meaningful for a full 6-die roll -- a partial reroll of
     * fewer dice can never satisfy "all six," so this is always false for
     * anything shorter.
     *
     * @param  list<bool>  $spicy  this roll's spicy flags, one per rolled die
     */
    public function isInstantWin(array $spicy): bool
    {
        return count($spicy) === DiceRoller::DICE_COUNT && ! in_array(false, $spicy, true);
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
