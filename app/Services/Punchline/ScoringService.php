<?php

namespace App\Services\Punchline;

/**
 * Pure vote-tally -> points math for one matchup (docs/specs/punchline.md
 * section 2's "Scoring" subsection). Has no idea who voted for what --
 * BallotService aggregates raw votes into per-answer counts first, this
 * class only ever sees the resulting counts.
 */
class ScoringService
{
    /**
     * @var array<int, int> round number => that round's per-matchup point pool
     */
    public const ROUND_POOLS = [1 => 1000, 2 => 2000, 3 => 3000];

    /**
     * Flat bonus rate applied on top of a sweep's already-100%-of-pool
     * score, scaled to the round's own pool rather than a flat number
     * that would mean less as the pools escalate (200/400/600 across
     * rounds 1-3 respectively).
     */
    public const SWEEP_BONUS_RATE = 0.2;

    public function poolForRound(int $round): int
    {
        return self::ROUND_POOLS[$round]
            ?? throw new \InvalidArgumentException("Punchline has no point pool defined for round {$round}.");
    }

    /**
     * Splits $pool proportionally by vote share among $answerIds. Every
     * id in $answerIds gets an entry in the result, including one with
     * zero votes. Rounds each share to the nearest 50 -- deliberately
     * *not* remainder-corrected back to an exact $pool total (a 1/1/4-of-6
     * vote split, for example, rounds 166.67/166.67/666.67 down to
     * 150/150/650, a real 50-point shortfall from $pool) -- accepted as
     * bounded, cosmetic drift rather than "fixed" with a largest-remainder
     * pass, the same simple-over-exact bias Farkle\Scorer already follows.
     *
     * @param  array<string, int>  $votesByAnswerId  answerId => vote count (missing id treated as 0)
     * @param  list<string>  $answerIds  every answer in the matchup, including ones with zero votes
     * @return array{pointsByAnswerId: array<string, int>, totalVotes: int, sweep: bool, sweepBonus: int, sweepAnswerId: ?string}
     */
    public function scoreMatchup(int $pool, array $votesByAnswerId, array $answerIds): array
    {
        $votes = [];
        foreach ($answerIds as $id) {
            $votes[$id] = $votesByAnswerId[$id] ?? 0;
        }

        $totalVotes = array_sum($votes);

        if ($totalVotes === 0) {
            $share = $this->roundToNearest50($pool / count($answerIds));
            $pointsByAnswerId = array_fill_keys($answerIds, $share);

            return [
                'pointsByAnswerId' => $pointsByAnswerId,
                'totalVotes' => 0,
                'sweep' => false,
                'sweepBonus' => 0,
                'sweepAnswerId' => null,
            ];
        }

        $pointsByAnswerId = [];
        foreach ($votes as $id => $count) {
            $pointsByAnswerId[$id] = $this->roundToNearest50($pool * $count / $totalVotes);
        }

        $answeredWithVotes = array_keys(array_filter($votes, fn (int $count) => $count > 0));
        $sweep = count($answeredWithVotes) === 1;
        $sweepAnswerId = $sweep ? $answeredWithVotes[0] : null;
        $sweepBonus = 0;

        if ($sweep) {
            $sweepBonus = $this->roundToNearest50($pool * self::SWEEP_BONUS_RATE);
            $pointsByAnswerId[$sweepAnswerId] += $sweepBonus;
        }

        return [
            'pointsByAnswerId' => $pointsByAnswerId,
            'totalVotes' => $totalVotes,
            'sweep' => $sweep,
            'sweepBonus' => $sweepBonus,
            'sweepAnswerId' => $sweepAnswerId,
        ];
    }

    public function roundToNearest50(float $value): int
    {
        return (int) (round($value / 50) * 50);
    }
}
