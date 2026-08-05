<?php

namespace App\Services\Farkle;

class Scorer
{
    /**
     * A turn's banked total must reach this on its own before a player is
     * "on the board" -- see docs/specs/farkle.md's "Win condition"
     * section. Every turn after that qualifies regardless of size.
     */
    public const MINIMUM_ENTRY_SCORE = 500;

    /**
     * Scores a chosen subset of a roll (the dice the player is setting
     * aside this roll), per docs/concerns.md's corrected scoring table:
     * single 1s/5s score individually (100/50); three of a kind scores by
     * face (1s = 1000, others = face x 100); four/five/six of a kind are
     * flat values (1000/2000/3000) *regardless of face* -- not a
     * per-face-scaled multiplier. Whichever interpretation of a given
     * count actually scores higher wins automatically (see
     * `bestScoreForFace()` -- e.g. four 1s scores 1100 by treating it as
     * three 1s (1000) plus one leftover single 1 (100), which beats the
     * flat four-of-a-kind value of 1000). Three special six-dice-only
     * patterns score their own flat bonus instead of decomposing by face
     * at all: a straight (one of each face), three pairs, or two
     * triplets.
     *
     * `valid` is false if *any* selected die doesn't contribute to the
     * score at all (e.g. a lone 3, or two 2s with nothing else) -- Farkle
     * only lets a player set aside dice that actually score, so a
     * selection containing a non-scoring die is simply illegal, not "worth
     * less."
     *
     * @param  list<int>  $values  the selected dice (a subset of one roll, not the whole roll)
     * @return array{score: int, valid: bool}
     */
    public function scoreSelection(array $values): array
    {
        if ($values === []) {
            return ['score' => 0, 'valid' => false];
        }

        if (count($values) === 6) {
            $special = $this->scoreSixDiceSpecial($values);

            if ($special !== null) {
                return ['score' => $special, 'valid' => true];
            }
        }

        $counts = $this->countsOf($values);
        $score = 0;

        foreach ($counts as $face => $count) {
            if ($count === 0) {
                continue;
            }

            // A leftover 1 or 2 of any face that isn't 1 or 5 never
            // scores on its own -- selecting it (without enough matches
            // to form a group) makes the whole selection illegal.
            if ($count < 3 && $face !== 1 && $face !== 5) {
                return ['score' => 0, 'valid' => false];
            }

            $score += $this->bestScoreForFace($face, $count);
        }

        return ['score' => $score, 'valid' => true];
    }

    /**
     * The maximum score obtainable from *every* die in a roll, auto-
     * including any scoring combination regardless of what a player may
     * or may not have manually selected -- see docs/concerns.md: clicking
     * Bank should bank every scoring die in the current roll, not just
     * the ones actually clicked. Never invalid (unlike scoreSelection) --
     * dice that can't contribute anything are simply worth 0 and left
     * out, rather than blocking the rest.
     *
     * @param  list<int>  $values  a full roll, not a manual selection
     */
    public function scoreMaximal(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        if (count($values) === 6) {
            $special = $this->scoreSixDiceSpecial($values);

            if ($special !== null) {
                return $special;
            }
        }

        $total = 0;

        foreach ($this->countsOf($values) as $face => $count) {
            $total += $this->bestScoreForFace($face, $count);
        }

        return $total;
    }

    /**
     * Whether a freshly rolled hand contains *any* scoring dice at all --
     * false means an immediate Farkle (the turn busts, whatever was
     * accumulated this turn is lost). True the instant there's a single 1
     * or 5 in the roll, three-of-a-kind of anything, or the full six dice
     * form a straight/three-pairs/two-triplets pattern -- the latter
     * matters because e.g. three pairs of non-1/5 faces (2-2-3-3-4-4) has
     * no individual scoring dice and no 3+-of-a-kind group on its own,
     * but is still very much a scoring roll.
     *
     * @param  list<int>  $values  a full roll, not a selection
     */
    public function hasAnyScoringDice(array $values): bool
    {
        if (count($values) === 6 && $this->scoreSixDiceSpecial($values) !== null) {
            return true;
        }

        $counts = $this->countsOf($values);

        return $counts[1] > 0 || $counts[5] > 0 || max($counts) >= 3;
    }

    /**
     * The higher-scoring of every valid way to score `$count` dice of the
     * same `$face` -- e.g. four 1s can be scored as a flat four-of-a-kind
     * (1000) or as three 1s (1000) plus one leftover single 1 (100); the
     * latter is worth more, so that's what's returned. For a count under
     * 3, this is just the leftover-single value (1/5 only; anything else
     * scores 0, meaning "not usable," not "invalid" -- that distinction
     * is the caller's job, since scoreMaximal() treats 0 as "left out"
     * while scoreSelection() treats it as illegal).
     */
    private function bestScoreForFace(int $face, int $count): int
    {
        if ($count === 0) {
            return 0;
        }

        $singleValue = match ($face) {
            1 => 100,
            5 => 50,
            default => 0,
        };

        if ($count < 3) {
            return $count * $singleValue;
        }

        $threeValue = $face === 1 ? 1000 : $face * 100;

        $candidates = [$threeValue + ($count - 3) * $singleValue];

        if ($count >= 4) {
            $candidates[] = 1000 + ($count - 4) * $singleValue;
        }

        if ($count >= 5) {
            $candidates[] = 2000 + ($count - 5) * $singleValue;
        }

        if ($count >= 6) {
            $candidates[] = 3000;
        }

        return max($candidates);
    }

    /**
     * Full-six-dice-only bonus patterns, scored flat instead of via the
     * usual per-face decomposition -- null if the six dice don't form one
     * of these exact shapes.
     *
     * @param  list<int>  $values  exactly 6 dice
     */
    private function scoreSixDiceSpecial(array $values): ?int
    {
        $groupSizes = array_values(array_filter($this->countsOf($values), fn (int $count) => $count > 0));
        sort($groupSizes);

        return match ($groupSizes) {
            [1, 1, 1, 1, 1, 1] => 1500, // a straight: 1-2-3-4-5-6
            [2, 2, 2] => 1500,          // three pairs
            [3, 3] => 2500,             // two triplets
            default => null,
        };
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
}
