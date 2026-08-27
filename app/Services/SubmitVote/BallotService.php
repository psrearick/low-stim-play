<?php

namespace App\Services\SubmitVote;

use Illuminate\Support\Facades\Cache;

/**
 * Owns the submit -> anonymize-reveal -> vote -> tally cycle any
 * submit-then-vote room game goes through, keyed by an opaque $ballotKey
 * the caller composes (Punchline: one per matchup, e.g.
 * "punchline:{sessionId}:round:{r}:matchup:{id}"; Hoax: one per round,
 * e.g. "hoax:{sessionId}:round:{r}") -- this class never constructs that
 * key itself and knows nothing about prompts, rounds, matchups, or
 * questions, only ids/seats/text. Originally built inside
 * `App\Services\Punchline` (docs/specs/punchline.md sections 3-4)
 * deliberately generic so it could be extracted once there was a second
 * real consumer to design the shared shape against, rather than guessed
 * at with only one -- Hoax (docs/specs/hoax.md) is that second consumer,
 * moved here unchanged (zero method/signature changes) once it existed.
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
     * Aggregated vote counts per answer, with no voter identity attached --
     * Punchline's only-ever use of the vote data, since its scoring is a
     * pure vote-share split that never needs to know *who* cast a
     * particular vote, only how many landed on each answer.
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
     * Every voter's own choice(s), read once at ballot close -- a second
     * deliberate exception to "who voted for what never leaves this
     * class," alongside authors() above. Punchline never calls this (its
     * scoring only ever needs tally()'s aggregated counts, so per-voter
     * identity really does stay secret forever for it); Hoax's own reveal
     * moment is fundamentally built around exposing exactly this ("you
     * fooled Alice and Bob!", "Priya found the real answer!"), so it isn't
     * optional for that consumer the way it is for Punchline.
     *
     * @return array<int, list<string>> voterSeatIndex => their chosen answerId(s)
     */
    public function voterChoices(string $ballotKey): array
    {
        return $this->votes($ballotKey);
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
