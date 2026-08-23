<?php

namespace App\Services\Punchline;

use InvalidArgumentException;

/**
 * Builds one round's matchup schedule for Punchline's pairing rounds
 * (rounds 1-2, docs/specs/punchline.md section 2) -- pure, no Eloquent/
 * Cache/state, so it's directly testable against the invariants that
 * section documents.
 */
class PairingService
{
    public const MIN_PLAYERS = 3;

    public const MAX_PLAYERS = 8;

    /**
     * Number of prompts a round needs for $playerCount seated players --
     * $playerCount for an even count (every matchup is a plain pair), or
     * $playerCount - 1 for an odd count (both pairing passes replace their
     * would-be leftover pair with one shared triple instead -- see
     * generateRoundMatchups()'s own docblock for why). Kept as its own
     * tested method since it's the detail most likely to get "fixed" back
     * to $playerCount for an odd count by a future reader who misses why
     * it isn't.
     */
    public function promptCountForRound(int $playerCount): int
    {
        return $playerCount % 2 === 0 ? $playerCount : $playerCount - 1;
    }

    /**
     * Builds one round's matchups for $playerCount seated players (seat
     * indices 0..$playerCount-1), so every player answers exactly 2
     * prompts this round against 2 different opponents. Two "passes"
     * each produce half of the round's matchups.
     *
     * Even player counts use the standard round-robin circle method (see
     * circlePairs()): fix the last seat, rotate the rest by one seat
     * between passes. This is provably repeat-free -- the two passes used
     * here are the first two rounds of a full round-robin schedule, which
     * together partition every possible pair exactly once.
     *
     * Odd player counts can't be fully paired (an odd seat count always
     * leaves one out of any single pass of pairs), so each pass instead
     * pairs off a "core" of the first $playerCount - 3 seats via the same
     * circle method and puts the remaining 3 seats into one shared 3-way
     * matchup -- for *both* passes, i.e. two triples for the round, not
     * one. (`docs/specs/punchline.md` section 2 works through why it has
     * to be two: every player appearing in exactly 2 matchups forces an
     * even count of odd-sized matchups, and one triple for the whole
     * round is mathematically impossible.) For $playerCount === 3 the
     * core is empty, so both prompts are simply the same trio; for
     * $playerCount === 5 the core is a single pair, so it necessarily
     * repeats across both passes -- both are accepted, unavoidable
     * results of the underlying combinatorics, not bugs.
     *
     * @return list<list<int>> each entry is one matchup's seat indices (2 or 3)
     */
    public function generateRoundMatchups(int $playerCount): array
    {
        if ($playerCount < self::MIN_PLAYERS || $playerCount > self::MAX_PLAYERS) {
            throw new InvalidArgumentException(
                'Punchline supports '.self::MIN_PLAYERS.'-'.self::MAX_PLAYERS." players, got {$playerCount}.",
            );
        }

        $isOdd = $playerCount % 2 !== 0;
        $coreSize = $isOdd ? $playerCount - 3 : $playerCount;
        $core = $coreSize > 0 ? range(0, $coreSize - 1) : [];
        $triple = $isOdd ? range($playerCount - 3, $playerCount - 1) : [];

        $matchups = [];

        foreach ([0, 1] as $pass) {
            foreach ($this->circlePairs($core, $pass) as $pair) {
                $matchups[] = $pair;
            }

            if ($isOdd) {
                $matchups[] = $triple;
            }
        }

        return $matchups;
    }

    /**
     * One pass of the standard round-robin circle method over $seats: fix
     * the last seat in place, rotate everyone else by $pass positions,
     * then pair the fixed seat with whichever seat lands first and
     * mirror-pair the rest. Returns [] for fewer than 2 seats (nothing to
     * pair -- $playerCount === 3's empty core).
     *
     * @param  list<int>  $seats
     * @return list<list<int>>
     */
    private function circlePairs(array $seats, int $pass): array
    {
        $count = count($seats);

        if ($count < 2) {
            return [];
        }

        $fixed = $seats[$count - 1];
        $others = array_slice($seats, 0, $count - 1);
        $rotated = $this->rotateRight($others, $pass);

        $pairs = [[$fixed, $rotated[0]]];

        for ($i = 1; $i < intdiv($count, 2); $i++) {
            $pairs[] = [$rotated[$i], $rotated[$count - 1 - $i]];
        }

        return $pairs;
    }

    /**
     * @param  list<int>  $items
     * @return list<int>
     */
    private function rotateRight(array $items, int $positions): array
    {
        $count = count($items);

        if ($count === 0) {
            return [];
        }

        $positions %= $count;

        if ($positions === 0) {
            return $items;
        }

        return array_merge(array_slice($items, -$positions), array_slice($items, 0, $count - $positions));
    }
}
