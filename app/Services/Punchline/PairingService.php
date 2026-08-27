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
     * always $playerCount, since every matchup (even or odd player count)
     * is a plain pair (docs/concerns.md: "every prompt should be
     * received by exactly two players").
     */
    public function promptCountForRound(int $playerCount): int
    {
        return $playerCount;
    }

    /**
     * Builds one round's matchups for $playerCount seated players (seat
     * indices 0..$playerCount-1), so every player answers exactly 2
     * prompts this round against 2 different opponents, and every prompt
     * is answered by exactly 2 players (docs/concerns.md).
     *
     * Even player counts use the standard round-robin circle method (see
     * circlePairs()): fix the last seat, rotate the rest by one seat
     * between passes, run twice. This is provably repeat-free -- the two
     * passes used here are the first two rounds of a full round-robin
     * schedule, which together partition every possible pair exactly
     * once.
     *
     * Odd player counts can't be fully paired by the circle method (an
     * odd seat count always leaves one seat out of any single pass of
     * pairs), so they instead use a single Hamiltonian cycle over all
     * seats -- seat 0 with seat 1, seat 1 with seat 2, ..., the last seat
     * back around to seat 0 (see cyclePairs()). That's $playerCount
     * pairs, every seat appearing in exactly 2 of them (its two
     * cycle-neighbors), and no pair repeated.
     *
     * @return list<list<int>> each entry is one matchup's seat indices (always 2)
     */
    public function generateRoundMatchups(int $playerCount): array
    {
        if ($playerCount < self::MIN_PLAYERS || $playerCount > self::MAX_PLAYERS) {
            throw new InvalidArgumentException(
                'Punchline supports '.self::MIN_PLAYERS.'-'.self::MAX_PLAYERS." players, got {$playerCount}.",
            );
        }

        if ($playerCount % 2 !== 0) {
            return $this->cyclePairs($playerCount);
        }

        $seats = range(0, $playerCount - 1);
        $matchups = [];

        foreach ([0, 1] as $pass) {
            foreach ($this->circlePairs($seats, $pass) as $pair) {
                $matchups[] = $pair;
            }
        }

        return $matchups;
    }

    /**
     * One pass of the standard round-robin circle method over $seats: fix
     * the last seat in place, rotate everyone else by $pass positions,
     * then pair the fixed seat with whichever seat lands first and
     * mirror-pair the rest.
     *
     * @param  list<int>  $seats
     * @return list<list<int>>
     */
    private function circlePairs(array $seats, int $pass): array
    {
        $count = count($seats);

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
     * A single Hamiltonian cycle over $playerCount seats: (0,1), (1,2),
     * ..., (playerCount-1, 0). Every seat appears in exactly 2 pairs (its
     * two neighbors in the cycle), and no pair repeats.
     *
     * @return list<list<int>>
     */
    private function cyclePairs(int $playerCount): array
    {
        return array_map(
            fn (int $seat) => [$seat, ($seat + 1) % $playerCount],
            range(0, $playerCount - 1),
        );
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
