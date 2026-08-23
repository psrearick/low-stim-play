<?php

namespace App\Services\SpellingBee;

class Scorer
{
    /**
     * Awarded on top of a word's own letter-count score when it uses every
     * one of the puzzle's 7 letters at least once (a "pangram") -- matches
     * docs/specs/spelling-bee.md's scoring table.
     */
    private const PANGRAM_BONUS = 7;

    /**
     * A 4-letter word scores 1 point; each letter beyond four adds one
     * more (`length - 3` gives exactly that: 4 => 1, 5 => 2, 6 => 3, ...),
     * plus the pangram bonus if it uses every one of the puzzle's letters.
     *
     * @param  list<string>  $letters  the puzzle's own 7 letters
     */
    public function pointsFor(string $word, array $letters): int
    {
        $points = mb_strlen($word) - 3;

        if ($this->isPangram($word, $letters)) {
            $points += self::PANGRAM_BONUS;
        }

        return $points;
    }

    /**
     * @param  list<string>  $letters
     */
    public function isPangram(string $word, array $letters): bool
    {
        return array_diff($letters, mb_str_split(strtolower($word))) === [];
    }

    /**
     * The total score a player would have if they found every one of the
     * puzzle's answer words -- needed by RankCalculator to turn a raw
     * score into a percentage-of-max tier.
     *
     * @param  list<string>  $words
     * @param  list<string>  $letters
     */
    public function maxPossible(array $words, array $letters): int
    {
        return array_sum(array_map(fn (string $word) => $this->pointsFor($word, $letters), $words));
    }
}
