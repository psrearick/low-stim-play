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
     * face (1s = 300, i.e. the same 100-per-die value as one/two 1s,
     * others = face x 100); four/five/six of a kind are flat values
     * (1000/2000/3000) *regardless of face* -- not a per-face-scaled
     * multiplier. Whichever interpretation of a given count actually
     * scores higher wins automatically (see `bestScoreForFace()` -- e.g.
     * four 2s scores 1000 (the flat four-of-a-kind value) rather than 700
     * (three 2s at 200 plus a leftover "single" 2, which isn't a real
     * scoring value at all), and four 1s scores the same flat 1000 rather
     * than 400 (three 1s at 300 plus one leftover single 1 at 100) since
     * 1000 is higher either way). Three special six-dice-only patterns
     * score their own flat bonus instead of decomposing by face at all: a
     * straight (one of each face), three pairs, or two triplets.
     *
     * `$spicy` is the Spicy variant's optional parallel flag array
     * (docs/specs/farkle.md §6.2) -- omit it (or pass all-false) for a
     * normal, non-Spicy game, which scores exactly as before. Each `true`
     * doubles whatever combination that die is part of; see
     * `bestScoreForFace()`/`scoreSixDiceSpecial()` for how that's applied.
     *
     * `valid` is false if *any* selected die doesn't contribute to the
     * score at all (e.g. a lone 3, or two 2s with nothing else) -- Farkle
     * only lets a player set aside dice that actually score, so a
     * selection containing a non-scoring die is simply illegal, not "worth
     * less."
     *
     * @param  list<int>  $values  the selected dice (a subset of one roll, not the whole roll)
     * @param  list<bool>  $spicy  parallel to $values; empty/omitted means "none spicy"
     * @return array{score: int, valid: bool}
     */
    public function scoreSelection(array $values, array $spicy = []): array
    {
        if ($values === []) {
            return ['score' => 0, 'valid' => false];
        }

        $spicy = $this->normalizeSpicy($values, $spicy);

        if (count($values) === 6) {
            $special = $this->scoreSixDiceSpecial($values, $spicy);

            if ($special !== null) {
                return ['score' => $special, 'valid' => true];
            }
        }

        $counts = $this->countsOf($values);
        $spicyCounts = $this->spicyCountsOf($values, $spicy);
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

            $score += $this->bestScoreForFace($face, $count, $spicyCounts[$face]);
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
     * @param  list<bool>  $spicy  parallel to $values; empty/omitted means "none spicy"
     */
    public function scoreMaximal(array $values, array $spicy = []): int
    {
        if ($values === []) {
            return 0;
        }

        $spicy = $this->normalizeSpicy($values, $spicy);

        if (count($values) === 6) {
            $special = $this->scoreSixDiceSpecial($values, $spicy);

            if ($special !== null) {
                return $special;
            }
        }

        $spicyCounts = $this->spicyCountsOf($values, $spicy);
        $total = 0;

        foreach ($this->countsOf($values) as $face => $count) {
            $total += $this->bestScoreForFace($face, $count, $spicyCounts[$face]);
        }

        return $total;
    }

    /**
     * Which indices of `$values` contribute nothing at all to
     * scoreMaximal() -- e.g. a lone 2 or a pair of 3s with nothing else.
     * Used by the High Stakes variant (docs/specs/farkle.md §6.5) to
     * figure out, right when a Bank auto-includes every scoring die in a
     * roll, exactly which dice were *not* part of that (the ones left
     * "non-frozen" for the next player to potentially take over) -- bank()
     * itself never asks the player to manually select anything, so this
     * is the only way to recover that split after the fact. Empty when
     * the six-dice special patterns apply, since every one of the 6 dice
     * contributes to those.
     *
     * @param  list<int>  $values  a full roll, not a selection
     * @return list<int> indices into $values
     */
    public function nonScoringIndices(array $values): array
    {
        if (count($values) === 6 && $this->scoreSixDiceSpecial($values) !== null) {
            return [];
        }

        $counts = $this->countsOf($values);

        return array_values(array_filter(
            array_keys($values),
            fn (int $i) => $values[$i] !== 1 && $values[$i] !== 5 && $counts[$values[$i]] < 3,
        ));
    }

    /**
     * Whether a freshly rolled hand contains *any* scoring dice at all --
     * false means an immediate Farkle (the turn busts, whatever was
     * accumulated this turn is lost). True the instant there's a single 1
     * or 5 in the roll, three-of-a-kind of anything, or the full six dice
     * form a straight/three-pairs/two-triplets pattern -- the latter
     * matters because e.g. three pairs of non-1/5 faces (2-2-3-3-4-4) has
     * no individual scoring dice and no 3+-of-a-kind group on its own,
     * but is still very much a scoring roll. Unaffected by the Spicy
     * variant -- doubling changes a roll's *value*, never whether it
     * scores at all.
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
     * same `$face` -- e.g. four 2s can be scored as a flat four-of-a-kind
     * (1000) or as three 2s (200) plus one leftover "single" 2 (0, since
     * only 1s/5s ever score individually); the former is worth more, so
     * that's what's returned. For a count under 3, this is just the
     * leftover-single value (1/5 only; anything else scores 0, meaning
     * "not usable," not "invalid" -- that distinction is the caller's
     * job, since scoreMaximal() treats 0 as "left out" while
     * scoreSelection() treats it as illegal).
     *
     * `$spicyCount` (docs/specs/farkle.md §6.2) is how many of this
     * face's dice are spicy -- at most 1 is ever actually possible (each
     * face value has exactly one physical die whose red face is that
     * value, see DiceRoller::rollSlots()), but this accepts any count for
     * robustness. Below 3-of-a-kind, each die is its own separate
     * single-die combination (see the `$count < 3` branch below) rather
     * than one group, so doubling is applied per-die there. At 3-of-a-kind
     * and above, every candidate is doubled by the same uniform
     * `2 ** $spicyCount` before taking max(), which is exactly equivalent
     * to doubling only the winning candidate's own combination: scaling a
     * whole list by the same positive constant never changes which
     * element is the max, and the winning candidate already always uses
     * every one of these `$count` dice as a single flat group (that's the
     * whole reason this method exists), so "doubled by every spicy die in
     * the face" and "doubled by every spicy die in the winning
     * combination" are the same set of dice for whichever candidate
     * actually wins.
     */
    private function bestScoreForFace(int $face, int $count, int $spicyCount = 0): int
    {
        if ($count === 0) {
            return 0;
        }

        $singleValue = match ($face) {
            1 => 100,
            5 => 50,
            default => 0,
        };

        // Below 3-of-a-kind, each 1/5 scores as its own separate
        // single-die combination, not one group (unlike every branch
        // below this) -- so a spicy die here only doubles its own
        // singleValue, not the whole lump: adding one extra $singleValue
        // per spicy die is equivalent to doubling just that die's share.
        if ($count < 3) {
            return $count * $singleValue + $spicyCount * $singleValue;
        }

        $threeValue = $face === 1 ? 3 * 100 : $face * 100;

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

        return max($candidates) * (2 ** $spicyCount);
    }

    /**
     * Full-six-dice-only bonus patterns, scored flat instead of via the
     * usual per-face decomposition -- null if the six dice don't form one
     * of these exact shapes. These already count as "one combination" per
     * docs/specs/farkle.md §2 ("replace the normal per-face decomposition
     * entirely"), so every spicy die among all 6 doubles the same flat
     * base value, per §6.2.
     *
     * @param  list<int>  $values  exactly 6 dice
     * @param  list<bool>  $spicy  parallel to $values
     */
    private function scoreSixDiceSpecial(array $values, array $spicy = []): ?int
    {
        $groupSizes = array_values(array_filter($this->countsOf($values), fn (int $count) => $count > 0));
        sort($groupSizes);

        $base = match ($groupSizes) {
            [1, 1, 1, 1, 1, 1] => 1500, // a straight: 1-2-3-4-5-6
            [2, 2, 2] => 1500,          // three pairs
            [3, 3] => 2500,             // two triplets
            default => null,
        };

        if ($base === null) {
            return null;
        }

        $spicyCount = count(array_filter($spicy));

        return $base * (2 ** $spicyCount);
    }

    /**
     * @param  list<int>  $values
     * @param  list<bool>  $spicy
     * @return list<bool> always the same length as $values
     */
    private function normalizeSpicy(array $values, array $spicy): array
    {
        return $spicy === [] ? array_fill(0, count($values), false) : $spicy;
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
     * @param  list<int>  $values
     * @param  list<bool>  $spicy  parallel to $values
     * @return array<int, int> count of spicy dice for each face 1-6, indexed by face
     */
    private function spicyCountsOf(array $values, array $spicy): array
    {
        $counts = array_fill(1, 6, 0);

        foreach ($values as $i => $value) {
            if ($spicy[$i] ?? false) {
                $counts[$value]++;
            }
        }

        return $counts;
    }
}
