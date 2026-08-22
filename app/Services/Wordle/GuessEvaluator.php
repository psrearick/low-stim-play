<?php

namespace App\Services\Wordle;

use App\Enums\WordleLetterStatus;

class GuessEvaluator
{
    /**
     * NYT's own two-pass rule (docs/specs/wordle.md section 3): exact-
     * position matches claim their letter from the answer's pool first,
     * then remaining guessed letters are checked against whatever's left --
     * so a guess that over-repeats a letter the answer only contains once
     * (or not at all) doesn't over-credit it as "present" more times than
     * it actually occurs.
     *
     * @return list<WordleLetterStatus> one entry per letter position
     */
    public function evaluate(string $answer, string $guess): array
    {
        $answerLetters = mb_str_split(strtolower($answer));
        $guessLetters = mb_str_split(strtolower($guess));
        $length = count($answerLetters);

        $result = array_fill(0, $length, WordleLetterStatus::Absent);

        /** @var array<string, int> */
        $remaining = [];

        foreach ($answerLetters as $i => $letter) {
            if ($guessLetters[$i] === $letter) {
                $result[$i] = WordleLetterStatus::Correct;
            } else {
                $remaining[$letter] = ($remaining[$letter] ?? 0) + 1;
            }
        }

        foreach ($guessLetters as $i => $letter) {
            if ($result[$i] === WordleLetterStatus::Correct) {
                continue;
            }

            if (($remaining[$letter] ?? 0) > 0) {
                $result[$i] = WordleLetterStatus::Present;
                $remaining[$letter]--;
            }
        }

        return $result;
    }

    /**
     * A fresh, empty hard-mode requirement set for a $length-letter game --
     * no position pinned yet, no letter minimums yet.
     *
     * @return array{positions: array<int, ?string>, minCounts: array<string, int>}
     */
    public function emptyHardModeRequirements(int $length): array
    {
        return ['positions' => array_fill(0, $length, null), 'minCounts' => []];
    }

    /**
     * Folds one guess's evaluation into the running hard-mode requirements:
     * any position revealed correct must recur there in every later guess,
     * and any letter revealed correct/present must reappear at least as
     * many times as the most any single previous guess ever revealed for
     * it.
     *
     * @param  array{positions: array<int, ?string>, minCounts: array<string, int>}  $requirements
     * @param  list<WordleLetterStatus>  $results
     * @return array{positions: array<int, ?string>, minCounts: array<string, int>}
     */
    public function mergeHardModeRequirements(array $requirements, string $guess, array $results): array
    {
        $guessLetters = mb_str_split(strtolower($guess));

        /** @var array<string, int> */
        $counts = [];

        foreach ($results as $i => $status) {
            if ($status === WordleLetterStatus::Correct) {
                $requirements['positions'][$i] = $guessLetters[$i];
            }

            if ($status === WordleLetterStatus::Correct || $status === WordleLetterStatus::Present) {
                $letter = $guessLetters[$i];
                $counts[$letter] = ($counts[$letter] ?? 0) + 1;
            }
        }

        foreach ($counts as $letter => $count) {
            $requirements['minCounts'][$letter] = max($requirements['minCounts'][$letter] ?? 0, $count);
        }

        return $requirements;
    }

    /**
     * The first hard-mode rule $guess breaks, as a player-facing message,
     * or null if it fully complies.
     *
     * @param  array{positions: array<int, ?string>, minCounts: array<string, int>}  $requirements
     */
    public function hardModeViolation(array $requirements, string $guess): ?string
    {
        $guessLetters = mb_str_split(strtolower($guess));

        foreach ($requirements['positions'] as $i => $letter) {
            if ($letter !== null && $guessLetters[$i] !== $letter) {
                return strtoupper($letter).' must be in position '.($i + 1).'.';
            }
        }

        foreach ($requirements['minCounts'] as $letter => $min) {
            $have = count(array_filter($guessLetters, fn (string $l): bool => $l === $letter));

            if ($have < $min) {
                return 'Guess must contain '.strtoupper($letter).'.';
            }
        }

        return null;
    }
}
