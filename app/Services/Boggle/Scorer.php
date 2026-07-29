<?php

namespace App\Services\Boggle;

class Scorer
{
    public const MIN_WORD_LENGTH = 3;

    /**
     * Points for a single word, by length: 3-4 letters = 1, 5 = 2, 6 = 3,
     * 7 = 5, 8+ = 11. Words shorter than the minimum are worth nothing.
     */
    public function pointsFor(string $word): int
    {
        $length = mb_strlen($word);

        return match (true) {
            $length < self::MIN_WORD_LENGTH => 0,
            $length <= 4 => 1,
            $length === 5 => 2,
            $length === 6 => 3,
            $length === 7 => 5,
            default => 11,
        };
    }

    /**
     * @param  iterable<string>  $words
     */
    public function totalFor(iterable $words): int
    {
        $total = 0;

        foreach ($words as $word) {
            $total += $this->pointsFor($word);
        }

        return $total;
    }
}
