<?php

namespace App\Services\Yahtzee;

class DiceRoller
{
    public const DICE_COUNT = 5;

    /**
     * @return list<int>
     */
    public function initial(): array
    {
        return array_map(fn () => random_int(1, 6), range(1, self::DICE_COUNT));
    }

    /**
     * Rerolls every die not marked as kept.
     *
     * @param  list<int>  $current
     * @param  list<bool>  $kept
     * @return list<int>
     */
    public function reroll(array $current, array $kept): array
    {
        return array_map(
            fn (int $value, bool $isKept) => $isKept ? $value : random_int(1, 6),
            $current,
            $kept,
        );
    }
}
