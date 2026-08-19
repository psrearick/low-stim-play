<?php

namespace App\Services\Farkle;

class DiceRoller
{
    public const DICE_COUNT = 6;

    /**
     * Rolls a fresh set of dice -- unlike Yahtzee's DiceRoller (which
     * always rerolls a fixed 5-die hand, keeping some in place), Farkle
     * dice already scored this turn are set aside entirely and never
     * shown again, so every roll here is simply "give me N brand new
     * dice," where N shrinks turn-to-turn as the player sets more aside.
     *
     * @return list<int>
     */
    public function roll(int $count): array
    {
        // Not range(1, $count) -- PHP's range() counts *down* when its
        // second argument is smaller than its first (range(1, 0) === [1,
        // 0], two elements, not zero), which would silently roll 2 dice
        // instead of 0 for the hot-dice edge case where $count is 0.
        return array_map(fn () => random_int(1, 6), array_fill(0, $count, null));
    }

    /**
     * Spicy-variant roll (docs/specs/farkle.md §6.1): each of the app's 6
     * conceptual dice is permanently labeled 1-6, and die N's one red
     * ("spicy") face is the value N -- so a die is spicy on a given roll
     * exactly when it lands on its own label. `$slots` is whichever
     * labels are still un-held this turn (shrinks as dice are set aside,
     * resets to all six on hot dice); only those dice get rolled, each
     * independently, and a spicy flag is returned alongside each value.
     *
     * @param  list<int>  $slots  die labels (1-6) still in play, no duplicates
     * @return array{values: list<int>, spicy: list<bool>}
     */
    public function rollSlots(array $slots): array
    {
        $values = [];
        $spicy = [];

        foreach ($slots as $slot) {
            $value = random_int(1, 6);
            $values[] = $value;
            $spicy[] = $value === $slot;
        }

        return ['values' => $values, 'spicy' => $spicy];
    }
}
