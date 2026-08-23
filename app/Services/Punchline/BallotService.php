<?php

namespace App\Services\Punchline;

use Illuminate\Support\Facades\Cache;

/**
 * Owns the submit -> anonymize-reveal -> vote -> tally cycle every
 * Punchline matchup goes through (docs/specs/punchline.md sections 3-4),
 * keyed by an opaque $ballotKey the caller composes (one per matchup, e.g.
 * "punchline:{sessionId}:round:{r}:matchup:{id}") -- this class never
 * constructs that key itself and knows nothing about prompts, rounds, or
 * matchups, only ids/seats/text, so it stays extraction-ready if a future
 * game (see docs/specs/punchline.md section 4) ever needs the same cycle.
 *
 * Directly extends the secret-attribution pattern Best Match's own
 * roundAttribution()/putRoundAttribution()/clearRoundAttribution()
 * established (resources/views/pages/apple-match/⚡play.blade.php): every
 * write here lives in `Cache` only, *never* a public Livewire property or
 * `GameSession.state`, since that whole blob is broadcast to every browser
 * and inspectable via devtools the instant it's set. Who-voted-for-what
 * additionally never leaves this class at all, not even at reveal --
 * tally() only ever returns aggregated per-answer counts, never per-voter
 * ballots.
 */
class BallotService
{
    /**
     * Same 3-hour TTL as Best Match's own attribution cache -- generous
     * insurance against an abandoned matchup, not a value with any other
     * significance.
     */
    private const TTL_HOURS = 3;

    /**
     * Records one seat's answer to one of its answer slots. $answerId is
     * caller-assigned (e.g. "{seatIndex}-{slotIndex}") and must be unique
     * within this ballot.
     */
    public function submit(string $ballotKey, int $seatIndex, string $answerId, string $text): void
    {
        $submissions = $this->submissions($ballotKey);
        $submissions[$answerId] = ['seatIndex' => $seatIndex, 'text' => $text];
        $this->putSubmissions($ballotKey, $submissions);
    }

    /**
     * How many answers this seat has filed so far on this ballot -- the
     * per-seat half of isComplete()'s check, also what the caller reads to
     * populate `state.matchups[].submittedCounts` and to guard "has this
     * seat already submitted its full quota" before accepting another.
     */
    public function submittedCount(string $ballotKey, int $seatIndex): int
    {
        return $this->submittedCounts($ballotKey)[$seatIndex] ?? 0;
    }

    /**
     * Whether every seat in $expectedSeats has filed $answersPerSeat
     * answers yet.
     *
     * @param  list<int>  $expectedSeats
     */
    public function isComplete(string $ballotKey, array $expectedSeats, int $answersPerSeat): bool
    {
        $counts = $this->submittedCounts($ballotKey);

        foreach ($expectedSeats as $seat) {
            if (($counts[$seat] ?? 0) < $answersPerSeat) {
                return false;
            }
        }

        return true;
    }

    /**
     * The public, safe-to-broadcast reveal: every submitted answer's text,
     * shuffled, with no seat mapping attached. Call once, right after
     * isComplete() first turns true, to build `state.matchups[].answers`.
     *
     * @return list<array{answerId: string, text: string}>
     */
    public function revealAnonymized(string $ballotKey): array
    {
        $anonymized = [];

        foreach ($this->submissions($ballotKey) as $answerId => $entry) {
            $anonymized[] = ['answerId' => $answerId, 'text' => $entry['text']];
        }

        shuffle($anonymized);

        return $anonymized;
    }

    /**
     * Records one seat's vote(s) -- a single-element $answerIds for a
     * pairing-round matchup, up to 3 (possibly repeated, i.e. stacked) for
     * round 3. Overwrites any previous vote from the same seat; the caller
     * is responsible for rejecting a second vote via hasVoted() if this
     * ballot's rules don't allow revoting.
     *
     * @param  list<string>  $answerIds
     */
    public function vote(string $ballotKey, int $voterSeatIndex, array $answerIds): void
    {
        $votes = $this->votes($ballotKey);
        $votes[$voterSeatIndex] = $answerIds;
        $this->putVotes($ballotKey, $votes);
    }

    public function hasVoted(string $ballotKey, int $voterSeatIndex): bool
    {
        return array_key_exists($voterSeatIndex, $this->votes($ballotKey));
    }

    /**
     * @return list<int>
     */
    public function votedSeats(string $ballotKey): array
    {
        return array_keys($this->votes($ballotKey));
    }

    /**
     * Aggregated vote counts per answer -- never per-voter identity, which
     * stays inside this class's own Cache entry forever (cleared, never
     * read back out, by clear()).
     *
     * @return array<string, int>
     */
    public function tally(string $ballotKey): array
    {
        $counts = [];

        foreach ($this->votes($ballotKey) as $answerIds) {
            foreach ($answerIds as $answerId) {
                $counts[$answerId] = ($counts[$answerId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * The real author of every answer -- read once, at matchup close, to
     * populate `state.matchups[].results.authorsByAnswerId` (the reveal
     * moment). Never read for any other purpose.
     *
     * @return array<string, int> answerId => seatIndex
     */
    public function authors(string $ballotKey): array
    {
        return array_map(
            fn (array $entry) => $entry['seatIndex'],
            $this->submissions($ballotKey),
        );
    }

    /**
     * Drops both this ballot's submissions and votes -- call once a
     * matchup has closed and its results have been copied into `state`.
     */
    public function clear(string $ballotKey): void
    {
        Cache::forget("{$ballotKey}:submissions");
        Cache::forget("{$ballotKey}:votes");
    }

    /**
     * @return array<string, array{seatIndex: int, text: string}>
     */
    private function submissions(string $ballotKey): array
    {
        return Cache::get("{$ballotKey}:submissions", []);
    }

    /**
     * @param  array<string, array{seatIndex: int, text: string}>  $submissions
     */
    private function putSubmissions(string $ballotKey, array $submissions): void
    {
        Cache::put("{$ballotKey}:submissions", $submissions, now()->addHours(self::TTL_HOURS));
    }

    /**
     * @return array<int>
     */
    private function submittedCounts(string $ballotKey): array
    {
        $counts = [];

        foreach ($this->submissions($ballotKey) as $entry) {
            $counts[$entry['seatIndex']] = ($counts[$entry['seatIndex']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return array<int, list<string>>
     */
    private function votes(string $ballotKey): array
    {
        return Cache::get("{$ballotKey}:votes", []);
    }

    /**
     * @param  array<int, list<string>>  $votes
     */
    private function putVotes(string $ballotKey, array $votes): void
    {
        Cache::put("{$ballotKey}:votes", $votes, now()->addHours(self::TTL_HOURS));
    }
}
