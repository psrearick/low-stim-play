<?php

namespace App\Services\Hoax;

/**
 * Pure per-round scoring math (docs/specs/hoax.md section 2's "Scoring"
 * subsection) -- the classic Fibbage formula, flat and the same every
 * round: every voter who picks the real answer earns TRUTH_POINTS, and
 * every player whose fake answer fools a voter earns FOOL_POINTS per
 * voter fooled (stacks). Unlike Punchline's ScoringService, this needs
 * per-voter identity, not just an aggregated tally -- see
 * `BallotService::voterChoices()`'s own docblock for why that's a second,
 * deliberate exception to "votes stay secret," specific to this game.
 */
class ScoringService
{
    public const TRUTH_POINTS = 1000;

    public const FOOL_POINTS = 500;

    /**
     * The sentinel "author" seat index submitted for the real answer --
     * never a valid room seat (seats are always >= 0), so it can never
     * collide with a real player and needs no special-casing anywhere
     * else in BallotService.
     */
    public const TRUTH_SEAT_INDEX = -1;

    /**
     * @param  array<int, string>  $voteByVoterSeat  voterSeatIndex => the single answerId they chose
     * @param  array<string, int>  $authorsByAnswerId  answerId => author seatIndex (TRUTH_SEAT_INDEX for the truth)
     * @return array{pointsBySeat: array<int, int>, seatsWhoFoundTruth: list<int>, votesByAnswerId: array<string, int>}
     */
    public function scoreRound(array $voteByVoterSeat, array $authorsByAnswerId, string $truthAnswerId): array
    {
        $pointsBySeat = [];
        $seatsWhoFoundTruth = [];
        $votesByAnswerId = [];

        foreach ($voteByVoterSeat as $voterSeat => $answerId) {
            $votesByAnswerId[$answerId] = ($votesByAnswerId[$answerId] ?? 0) + 1;

            if ($answerId === $truthAnswerId) {
                $pointsBySeat[$voterSeat] = ($pointsBySeat[$voterSeat] ?? 0) + self::TRUTH_POINTS;
                $seatsWhoFoundTruth[] = $voterSeat;

                continue;
            }

            $authorSeat = $authorsByAnswerId[$answerId] ?? null;

            if ($authorSeat !== null && $authorSeat !== self::TRUTH_SEAT_INDEX) {
                $pointsBySeat[$authorSeat] = ($pointsBySeat[$authorSeat] ?? 0) + self::FOOL_POINTS;
            }
        }

        return [
            'pointsBySeat' => $pointsBySeat,
            'seatsWhoFoundTruth' => $seatsWhoFoundTruth,
            'votesByAnswerId' => $votesByAnswerId,
        ];
    }
}
