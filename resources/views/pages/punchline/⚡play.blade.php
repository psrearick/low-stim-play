<?php

use App\Enums\GameResult;
use App\Enums\GameType;
use App\Enums\PunchlineMatchupStatus;
use App\Enums\PunchlinePhase;
use App\Enums\RoomStatus;
use App\Events\RoomGameStateUpdated;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\PunchlinePrompt;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Services\Punchline\BallotService;
use App\Services\Punchline\FillerAnswers;
use App\Services\Punchline\PairingService;
use App\Services\Punchline\ScoringService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    // 75s/150s (docs/specs/punchline.md's original "roughly 60-90s" estimate,
    // doubled for round 3) felt too tight in real play -- bumped to
    // 120s/240s, then to a flat 3 minutes for both phases of every round
    // (docs/concerns.md) once players wanted more breathing room for
    // voting too, not just writing.
    public const int WRITING_SECONDS = 180;

    public const int VOTING_SECONDS = 180;

    /**
     * The online room this play-through belongs to. Punchline has no
     * pass-and-play mode (docs/specs/punchline.md section 1), same
     * reasoning as Best Match -- a bare visit only ever offers a "play in
     * a room you host" list.
     */
    #[Url]
    public ?string $room = null;

    public bool $started = false;

    public ?int $gameSessionId = null;

    public int $round = 1;

    public PunchlinePhase $phase = PunchlinePhase::Writing;

    public int $pool = 1000;

    /**
     * @var list<array{seatIndex: int, name: string}>
     */
    public array $seats = [];

    /**
     * seatIndex => cumulative score.
     *
     * @var array<int, int>
     */
    public array $scores = [];

    /**
     * seatIndex => [1 => points, 2 => points, 3 => points], for the
     * per-round breakdown in GameParticipant.stats.
     *
     * @var array<int, array<int, int>>
     */
    public array $pointsByRound = [];

    /**
     * seatIndex => number of matchups swept.
     *
     * @var array<int, int>
     */
    public array $sweepsWon = [];

    public ?int $writingEndsAt = null;

    public ?int $votingEndsAt = null;

    /**
     * @var list<int>
     */
    public array $usedPromptIds = [];

    /**
     * @var list<array{id: string, promptId: int, promptText: string, seatIndices: list<int>, answersPerSeat: int, voteLimit: int, status: string, submittedCounts: array<int, int>, votedSeats: list<int>, answers: list<array{answerId: string, text: string}>, results: ?array}>
     */
    public array $matchups = [];

    public ?string $message = null;

    /**
     * This connection's seat, or null for an unseated spectator.
     */
    public ?int $mySeatIndex = null;

    /**
     * In-progress, unsubmitted draft text -- keyed "{matchupId}-{slot}".
     * Deliberately never included in syncOnlineState()'s $state or read by
     * anyone else's connection; Livewire only ever broadcasts the explicit
     * RoomGameStateUpdated payload, never a component's own properties, so
     * this never leaves the browser it's typed in.
     *
     * @var array<string, string>
     */
    public array $drafts = [];

    /**
     * Round 3's per-answer vote allocation this connection is currently
     * building (0-3 votes per answerId, summing to at most 3) -- local UI
     * state only, submitted all at once via submitRoundThreeVotes().
     *
     * @var array<string, int>
     */
    public array $voteAllocation = [];

    private PairingService $pairingService;

    private ScoringService $scoringService;

    private BallotService $ballotService;

    private FillerAnswers $fillerAnswers;

    public function boot(
        PairingService $pairingService,
        ScoringService $scoringService,
        BallotService $ballotService,
        FillerAnswers $fillerAnswers,
    ): void {
        $this->pairingService = $pairingService;
        $this->scoringService = $scoringService;
        $this->ballotService = $ballotService;
        $this->fillerAnswers = $fillerAnswers;
    }

    public function mount(): void
    {
        if ($this->room === null) {
            return;
        }

        $roomModel = $this->resolveRoom();
        $session = $roomModel->gameSession;

        if ($session === null || $session->type !== GameType::Punchline) {
            $this->redirect(route('room.show', ['code' => $roomModel->code]));

            return;
        }

        $this->gameSessionId = $session->id;
        $this->hydrateFromState($session->state);

        $seat = RoomSeat::where('room_id', $roomModel->id)->where('user_id', Auth::id())->first();
        $this->mySeatIndex = $seat?->seat_index;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function hydrateFromState(array $state): void
    {
        $this->round = $state['round'];
        $this->phase = PunchlinePhase::from($state['phase']);
        $this->pool = $state['pool'];
        $this->seats = $state['seats'];
        $this->scores = $state['scores'];
        $this->pointsByRound = $state['pointsByRound'];
        $this->sweepsWon = $state['sweepsWon'];
        $this->writingEndsAt = $state['writingEndsAt'];
        $this->votingEndsAt = $state['votingEndsAt'];
        $this->usedPromptIds = $state['usedPromptIds'];
        $this->matchups = $state['matchups'];
        $this->message = $state['message'];
        $this->started = true;
    }

    /**
     * Re-reads GameSession.state fresh from the database and re-hydrates
     * every property from it -- used at the top of submitAnswer()/
     * castVote() specifically because this connection's last-known
     * snapshot can be stale (see both methods' own docblocks) in a way no
     * other room game in this app is exposed to at the same frequency.
     * No lock needed here (unlike checkTimeout()'s own DB::transaction +
     * lockForUpdate) -- this is a plain read to catch up with reality
     * before validating, not a read-modify-write race.
     */
    private function refreshFromDatabase(): void
    {
        $state = GameSession::find($this->gameSessionId)?->state;

        if ($state !== null) {
            $this->hydrateFromState($state);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function stateArray(): array
    {
        return [
            'round' => $this->round,
            'phase' => $this->phase->value,
            'pool' => $this->pool,
            'seats' => $this->seats,
            'scores' => $this->scores,
            'pointsByRound' => $this->pointsByRound,
            'sweepsWon' => $this->sweepsWon,
            'writingEndsAt' => $this->writingEndsAt,
            'votingEndsAt' => $this->votingEndsAt,
            'usedPromptIds' => $this->usedPromptIds,
            'matchups' => $this->matchups,
            'message' => $this->message,
        ];
    }

    private function resolveRoom(): Room
    {
        return Room::where('code', $this->room)->firstOrFail();
    }

    /**
     * Persists + broadcasts the shared round state. Wrapped in a
     * row-locked transaction as cheap insurance against a double-tap/retry
     * racing itself, same as every other game's own syncOnlineState() --
     * checkTimeout() below needs a stronger version of this same
     * insurance, since unlike a seat's own action, any connected client
     * can trigger it concurrently.
     */
    private function syncOnlineState(): void
    {
        $state = $this->stateArray();

        DB::transaction(function () use ($state) {
            GameSession::where('id', $this->gameSessionId)->lockForUpdate()->first();
            GameSession::find($this->gameSessionId)->update(['state' => $state]);
        });

        RoomGameStateUpdated::dispatch($this->resolveRoom(), $state);
    }

    /**
     * @param  array{state: array<string, mixed>}  $event
     */
    public function onStateUpdated(array $event): void
    {
        $this->hydrateFromState($event['state']);
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        if ($this->room === null) {
            return [];
        }

        return [
            "echo-presence:room.{$this->room},RoomGameStateUpdated" => 'onStateUpdated',
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Room>
     */
    public function getHostedRoomsProperty(): \Illuminate\Support\Collection
    {
        if (Auth::guest()) {
            return collect();
        }

        return Room::where('host_user_id', Auth::id())->notExpired()->get();
    }

    public function getIsSpectatorProperty(): bool
    {
        return $this->room !== null && $this->mySeatIndex === null;
    }

    /**
     * Same computed-property shape as pages::punchline.room's own
     * getIsHostProperty() -- $this->room is always set here (mount()
     * redirects away otherwise), so no null-room fallback is needed the
     * way Farkle's solo-capable getCanEndGameManuallyProperty() has one.
     */
    public function getIsHostProperty(): bool
    {
        return Auth::id() === $this->resolveRoom()->host_user_id;
    }

    /**
     * Mirrors pages::farkle.play's own getCanEndGameManuallyProperty()
     * (docs/concerns.md) -- host-only, and only while there's still a game
     * to end.
     */
    public function getCanEndGameManuallyProperty(): bool
    {
        return $this->phase !== PunchlinePhase::GameEnd && $this->isHost;
    }

    /**
     * How many seconds remain in the current phase's deadline, or null
     * during a phase with no countdown (RoundReveal/GameEnd). Recomputed
     * fresh on every render/poll tick -- not cached, since the whole point
     * is that it changes between ticks.
     */
    public function getSecondsRemainingProperty(): ?int
    {
        $deadline = match ($this->phase) {
            PunchlinePhase::Writing => $this->writingEndsAt,
            PunchlinePhase::Voting => $this->votingEndsAt,
            default => null,
        };

        return $deadline === null ? null : max(0, $deadline - now()->timestamp);
    }

    public function seatName(int $seatIndex): ?string
    {
        return collect($this->seats)->firstWhere('seatIndex', $seatIndex)['name'] ?? null;
    }

    /**
     * @return list<string>
     */
    public function getWinnerNamesProperty(): array
    {
        if ($this->scores === []) {
            return [];
        }

        $topScore = max($this->scores);

        return collect($this->scores)
            ->filter(fn (int $score) => $score === $topScore)
            ->keys()
            ->map(fn (int $seatIndex) => $this->seatName($seatIndex))
            ->filter()
            ->values()
            ->all();
    }

    private function ballotKey(string $matchupId): string
    {
        return "punchline:{$this->gameSessionId}:round:{$this->round}:matchup:{$matchupId}";
    }

    /**
     * Null (not a 404) when $matchupId isn't in the current round's
     * matchups -- entirely possible after refreshFromDatabase() if the
     * round already advanced elsewhere between this connection's last
     * update and this click (round advancement replaces $this->matchups
     * wholesale, per buildRoundMatchups()). Callers treat that as a
     * benign "too late, the round moved on" no-op, same reasoning as
     * submitAnswer()/castVote()'s own staleness handling.
     */
    private function findMatchupIndex(string $matchupId): ?int
    {
        foreach ($this->matchups as $i => $matchup) {
            if ($matchup['id'] === $matchupId) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Every seat allowed to vote on $matchup: everyone *not* in it, unless
     * that set is empty (only possible when the matchup already contains
     * every seated player -- round 3, or a P=3 game's degenerate pairing-
     * round trio), in which case its own authors vote on each other's
     * answers instead. One rule, both cases -- see docs/specs/punchline.md
     * section 2.
     *
     * @param  array<string, mixed>  $matchup
     * @return list<int>
     */
    private function eligibleVoters(array $matchup): array
    {
        $everyone = array_column($this->seats, 'seatIndex');
        $eligible = array_values(array_diff($everyone, $matchup['seatIndices']));

        return $eligible === [] ? $matchup['seatIndices'] : $eligible;
    }

    public function matchupIsAuthor(array $matchup): bool
    {
        return $this->mySeatIndex !== null && in_array($this->mySeatIndex, $matchup['seatIndices'], true);
    }

    public function matchupCanVote(array $matchup): bool
    {
        return $this->mySeatIndex !== null
            && $matchup['status'] === PunchlineMatchupStatus::Voting->value
            && in_array($this->mySeatIndex, $this->eligibleVoters($matchup), true)
            && ! $this->ballotService->hasVoted($this->ballotKey($matchup['id']), $this->mySeatIndex);
    }

    /**
     * $matchup['answers'] minus this seat's own answer(s) -- the real,
     * previously-missing fix for the reported "voting sometimes redirects
     * home" bug. Every voter-facing vote UI must render this instead of
     * the raw answers list: whenever eligibleVoters() falls back to
     * "everyone votes" (a 3-player game, where every matchup includes
     * every seat, or round 3, which always does), the current voter is
     * also one of the matchup's own authors, and the anonymized list
     * gives them no way to visually tell their own answer apart from
     * anyone else's -- so they could only ever avoid clicking it by luck.
     * Clicking it hit the server's real, correct "never vote for your own
     * answer" guard (still enforced below in castVote(), unchanged), which
     * 403'd and silently redirected home via this app's global Livewire
     * error handler. This never showed up for a normal (4+ player)
     * external voter, since none of a matchup's answers are ever theirs
     * to begin with -- this filter is a no-op for them.
     *
     * @return list<array{answerId: string, text: string}>
     */
    public function matchupVotableAnswers(array $matchup): array
    {
        if ($this->mySeatIndex === null) {
            return $matchup['answers'];
        }

        return array_values(array_filter(
            $matchup['answers'],
            fn (array $answer) => ! str_starts_with($answer['answerId'], "{$this->mySeatIndex}-"),
        ));
    }

    public function matchupHasVoted(array $matchup): bool
    {
        return $this->mySeatIndex !== null
            && $this->ballotService->hasVoted($this->ballotKey($matchup['id']), $this->mySeatIndex);
    }

    public function matchupMySubmittedCount(array $matchup): int
    {
        return $matchup['submittedCounts'][$this->mySeatIndex] ?? 0;
    }

    public function matchupVotesAllowed(array $matchup): int
    {
        return $matchup['voteLimit'];
    }

    /**
     * Writes one answer to one of this seat's answer slots on $matchupId,
     * reading the text from $this->drafts (already synced server-side by
     * the input's own wire:model before this action runs, so no need to
     * pass it as a parameter). The slot index is derived server-side from
     * how many this seat has already filed, so a client can't skip/repeat
     * a slot by hand-crafting the call.
     *
     * Re-hydrates from the database first (see castVote()'s own note on
     * why) -- with several matchups progressing independently plus
     * checkTimeout()'s own 2s poll, this connection's last-known snapshot
     * can genuinely be stale (another author just finished writing and
     * flipped this matchup to Voting, or a fast double-tap on Submit beat
     * the DOM update back). A stale "matchup already left Writing" or
     * "already filed this slot" is a moot no-op, not a violation, so both
     * return quietly instead of a hard 403 -- which this app's global
     * Livewire error handler (resources/js/app.js) otherwise turns into a
     * silent redirect to the homepage, exactly the reported bug.
     */
    public function submitAnswer(string $matchupId): void
    {
        abort_unless($this->mySeatIndex !== null, 403);

        $this->refreshFromDatabase();

        $i = $this->findMatchupIndex($matchupId);

        if ($i === null) {
            return;
        }

        $matchup = $this->matchups[$i];

        abort_unless(in_array($this->mySeatIndex, $matchup['seatIndices'], true), 403);

        if ($matchup['status'] !== PunchlineMatchupStatus::Writing->value) {
            return;
        }

        $ballotKey = $this->ballotKey($matchupId);
        $slot = $this->ballotService->submittedCount($ballotKey, $this->mySeatIndex);

        if ($slot >= $matchup['answersPerSeat']) {
            return;
        }

        $text = trim($this->drafts["{$matchupId}-{$slot}"] ?? '');
        abort_unless($text !== '', 422);

        $this->ballotService->submit($ballotKey, $this->mySeatIndex, "{$this->mySeatIndex}-{$slot}", $text);
        $this->matchups[$i]['submittedCounts'][$this->mySeatIndex] = $slot + 1;

        unset($this->drafts["{$matchupId}-{$slot}"]);

        if ($this->ballotService->isComplete($ballotKey, $matchup['seatIndices'], $matchup['answersPerSeat'])) {
            $this->matchups[$i]['answers'] = $this->ballotService->revealAnonymized($ballotKey);
            $this->matchups[$i]['status'] = PunchlineMatchupStatus::Voting->value;
        }

        $this->advanceRoundPhaseIfReady();
        $this->syncOnlineState();
    }

    /**
     * @param  list<string>  $answerIds
     *
     * Re-hydrates from the database first, same reasoning as
     * submitAnswer() -- unlike every other room game here (one shared
     * phase for the whole table, so a click is almost always still valid
     * by the time it lands), Punchline has several matchups closing
     * independently, any of which can flip to Closed between this
     * connection's last update and this click landing (another voter's
     * vote, or checkTimeout()'s own 2s poll elsewhere firing first). A
     * stale "matchup already closed" or "already voted" is a moot no-op
     * -- the UI will already show the real state once this re-hydrate
     * runs -- not a violation worth a hard 403, which this app's global
     * Livewire error handler (resources/js/app.js) otherwise turns into a
     * silent redirect to the homepage on any non-422 failure.
     */
    public function castVote(string $matchupId, array $answerIds): void
    {
        abort_unless($this->mySeatIndex !== null, 403);

        $this->refreshFromDatabase();

        $i = $this->findMatchupIndex($matchupId);

        if ($i === null) {
            return;
        }

        $matchup = $this->matchups[$i];

        abort_unless(in_array($this->mySeatIndex, $this->eligibleVoters($matchup), true), 403);

        $ballotKey = $this->ballotKey($matchupId);

        if ($matchup['status'] !== PunchlineMatchupStatus::Voting->value || $this->ballotService->hasVoted($ballotKey, $this->mySeatIndex)) {
            return;
        }

        $voteLimit = $this->matchupVotesAllowed($matchup);
        abort_unless(count($answerIds) >= 1 && count($answerIds) <= $voteLimit, 422);

        $validAnswerIds = array_column($matchup['answers'], 'answerId');
        foreach ($answerIds as $answerId) {
            abort_unless(in_array($answerId, $validAnswerIds, true), 422);
        }

        // Reads real authorship only to enforce "never vote for your own
        // answer" -- a server-side check inside the one action that needs
        // it, never assigned to a public property or included in $state,
        // same sanctioned use Best Match's own pickWinner() already
        // established for reading its round attribution. Silently drops
        // any self-authored id instead of aborting the whole vote --
        // matchupVotableAnswers() now keeps the real UI from ever offering
        // one, so this is defense in depth, not the primary fix (see that
        // method's own docblock for the bug this class of hard-abort
        // caused: a 3-player game, where every matchup includes every
        // seat, gave a voter no visual way to tell their own anonymized
        // answer apart from anyone else's, so clicking it -- entirely by
        // accident -- 403'd and silently redirected home).
        $authors = $this->ballotService->authors($ballotKey);
        $answerIds = array_values(array_filter(
            $answerIds,
            fn (string $answerId) => ($authors[$answerId] ?? null) !== $this->mySeatIndex,
        ));

        if ($answerIds === []) {
            return;
        }

        $this->ballotService->vote($ballotKey, $this->mySeatIndex, $answerIds);
        $this->matchups[$i]['votedSeats'][] = $this->mySeatIndex;

        $eligible = $this->eligibleVoters($matchup);
        $voted = $this->matchups[$i]['votedSeats'];

        if (count(array_intersect($eligible, $voted)) >= count($eligible)) {
            $this->closeMatchup($i);
        }

        $this->advanceRoundPhaseIfReady();
        $this->syncOnlineState();
    }

    /**
     * Round 3's own vote UI works in two steps -- adjustVoteAllocation()
     * builds up to 3 votes locally (never synced), submitRoundThreeVotes()
     * flattens the allocation into a repeated-answerId list and hands it
     * to the same castVote() every other matchup uses.
     */
    public function adjustVoteAllocation(string $answerId, int $delta): void
    {
        $current = $this->voteAllocation[$answerId] ?? 0;
        $total = array_sum($this->voteAllocation);

        if ($delta > 0 && $total >= 3) {
            return;
        }

        $next = max(0, $current + $delta);
        $this->voteAllocation[$answerId] = $next;

        if ($next === 0) {
            unset($this->voteAllocation[$answerId]);
        }
    }

    public function submitRoundThreeVotes(string $matchupId): void
    {
        $answerIds = [];

        foreach ($this->voteAllocation as $answerId => $count) {
            for ($n = 0; $n < $count; $n++) {
                $answerIds[] = $answerId;
            }
        }

        abort_unless($answerIds !== [], 422);

        $this->castVote($matchupId, $answerIds);
        $this->voteAllocation = [];
    }

    /**
     * Scores $matchups[$i] from its ballot's vote tally, applies points to
     * $scores/$pointsByRound (and $sweepsWon on a sweep), then clears its
     * secret ballot state -- the reveal moment, both for authorship
     * (results.authorsByAnswerId, safe to broadcast from here on) and for
     * the vote tally itself.
     */
    private function closeMatchup(int $i): void
    {
        $matchup = $this->matchups[$i];
        $ballotKey = $this->ballotKey($matchup['id']);

        $answerIds = array_column($matchup['answers'], 'answerId');
        $tally = $this->ballotService->tally($ballotKey);
        $authors = $this->ballotService->authors($ballotKey);

        $result = $this->scoringService->scoreMatchup($this->pool, $tally, $answerIds);
        $result['authorsByAnswerId'] = $authors;

        foreach ($result['pointsByAnswerId'] as $answerId => $points) {
            $seatIndex = $authors[$answerId];
            $this->scores[$seatIndex] = ($this->scores[$seatIndex] ?? 0) + $points;
            $this->pointsByRound[$seatIndex][$this->round] = ($this->pointsByRound[$seatIndex][$this->round] ?? 0) + $points;
        }

        if ($result['sweep'] && $result['sweepAnswerId'] !== null) {
            $sweepSeat = $authors[$result['sweepAnswerId']];
            $this->sweepsWon[$sweepSeat] = ($this->sweepsWon[$sweepSeat] ?? 0) + 1;
        }

        $this->matchups[$i]['results'] = $result;
        $this->matchups[$i]['status'] = PunchlineMatchupStatus::Closed->value;

        $this->ballotService->clear($ballotKey);
    }

    /**
     * The round stays Writing until *every* matchup has left writing (even
     * though an individual matchup opens for voting the moment its own
     * authors finish), and stays Voting until every matchup has closed.
     * Called after any action that might have just completed the last
     * remaining matchup, from both the normal actions below and from the
     * timeout paths, so both funnel through one place.
     */
    private function advanceRoundPhaseIfReady(): void
    {
        if ($this->phase === PunchlinePhase::Writing
            && collect($this->matchups)->every(fn (array $m) => $m['status'] !== PunchlineMatchupStatus::Writing->value)) {
            $this->phase = PunchlinePhase::Voting;
            $this->writingEndsAt = null;
            $this->votingEndsAt = now()->addSeconds(self::VOTING_SECONDS)->timestamp;
        }

        if ($this->phase === PunchlinePhase::Voting
            && collect($this->matchups)->every(fn (array $m) => $m['status'] === PunchlineMatchupStatus::Closed->value)) {
            $this->phase = PunchlinePhase::RoundReveal;
            $this->votingEndsAt = null;
        }
    }

    /**
     * Any connected client polls this (see wire:poll below), not just a
     * turn-holder -- so unlike every other mutating action, this can be
     * called concurrently by more than one browser at once. Re-locks and
     * re-hydrates from the database *inside* the transaction rather than
     * trusting this connection's own already-loaded properties, so a
     * second concurrent tick that arrives just after the first has already
     * applied the timeout finds it already handled and does nothing.
     */
    public function checkTimeout(): void
    {
        $didMutate = false;

        DB::transaction(function () use (&$didMutate) {
            $session = GameSession::where('id', $this->gameSessionId)->lockForUpdate()->first();

            if ($session === null) {
                return;
            }

            $this->hydrateFromState($session->state);
            $now = now()->timestamp;

            if ($this->phase === PunchlinePhase::Writing && $this->writingEndsAt !== null && $now >= $this->writingEndsAt) {
                $this->applyWritingTimeout();
                $didMutate = true;
            } elseif ($this->phase === PunchlinePhase::Voting && $this->votingEndsAt !== null && $now >= $this->votingEndsAt) {
                $this->applyVotingTimeout();
                $didMutate = true;
            }

            if ($didMutate) {
                $session->update(['state' => $this->stateArray()]);
            }
        });

        if ($didMutate) {
            RoomGameStateUpdated::dispatch($this->resolveRoom(), $this->stateArray());
        }
    }

    /**
     * Auto-fills every still-missing answer slot in every still-writing
     * matchup from FillerAnswers -- no default score is ever awarded for
     * one of these, so their only job is letting the round move on instead
     * of stalling forever on one slow player.
     */
    private function applyWritingTimeout(): void
    {
        foreach ($this->matchups as $i => $matchup) {
            if ($matchup['status'] !== PunchlineMatchupStatus::Writing->value) {
                continue;
            }

            $ballotKey = $this->ballotKey($matchup['id']);

            foreach ($matchup['seatIndices'] as $seatIndex) {
                $count = $this->ballotService->submittedCount($ballotKey, $seatIndex);

                for ($slot = $count; $slot < $matchup['answersPerSeat']; $slot++) {
                    $this->ballotService->submit($ballotKey, $seatIndex, "{$seatIndex}-{$slot}", $this->fillerAnswers->pick($slot));
                }

                $this->matchups[$i]['submittedCounts'][$seatIndex] = $matchup['answersPerSeat'];
            }

            $this->matchups[$i]['answers'] = $this->ballotService->revealAnonymized($ballotKey);
            $this->matchups[$i]['status'] = PunchlineMatchupStatus::Voting->value;
        }

        $this->advanceRoundPhaseIfReady();
    }

    /**
     * Force-closes every matchup still mid-vote once the round's voting
     * deadline passes -- whatever votes exist so far are scored as-is
     * (ScoringService's own zero-vote fallback handles a matchup nobody
     * got to vote on at all).
     */
    private function applyVotingTimeout(): void
    {
        foreach ($this->matchups as $i => $matchup) {
            if ($matchup['status'] === PunchlineMatchupStatus::Voting->value) {
                $this->closeMatchup($i);
            }
        }

        $this->advanceRoundPhaseIfReady();
    }

    /**
     * Host-only (docs/concerns.md) -- previously any seated player could
     * advance, on the theory that "ready to continue" was a shared
     * decision everyone would tap in unison; in practice that just meant
     * anyone could rush the room past a reveal others were still reading.
     * Still re-syncs first and returns quietly rather than 403ing if the
     * phase has already moved on: the host's own double-click (or a
     * stale page reload) racing itself is still possible even with a
     * single allowed clicker, and this app's global Livewire error
     * handler turns a real 403 into a silent redirect to the homepage,
     * which would be a worse experience than a no-op.
     */
    public function advanceRound(): void
    {
        abort_unless($this->isHost, 403);

        $this->refreshFromDatabase();

        if ($this->phase !== PunchlinePhase::RoundReveal) {
            return;
        }

        if ($this->round >= 3) {
            $this->finishGame();
            $this->phase = PunchlinePhase::GameEnd;
            $this->syncOnlineState();

            return;
        }

        $this->round++;
        $this->pool = $this->scoringService->poolForRound($this->round);
        $this->buildRoundMatchups();
        $this->phase = PunchlinePhase::Writing;
        $this->writingEndsAt = now()->addSeconds(self::WRITING_SECONDS)->timestamp;
        $this->votingEndsAt = null;

        $this->syncOnlineState();
    }

    /**
     * Builds the next round's matchups -- round 3 is modeled as one
     * matchup whose seatIndices is every seat, answering the single
     * shared prompt once each (answersPerSeat 1) but voting with 3
     * stackable votes (voteLimit 3, decoupled from answersPerSeat --
     * docs/concerns.md), the same submit/reveal/vote/tally machinery as a
     * pairing-round matchup, not a separate game mode (docs/specs/
     * punchline.md section 2).
     */
    private function buildRoundMatchups(): void
    {
        if ($this->round === 3) {
            $promptId = $this->pickPrompts(1)[0];

            $this->matchups = [[
                'id' => "r{$this->round}-0",
                'promptId' => $promptId,
                'promptText' => PunchlinePrompt::find($promptId)->text,
                'seatIndices' => array_column($this->seats, 'seatIndex'),
                'answersPerSeat' => 1,
                'voteLimit' => 3,
                'status' => PunchlineMatchupStatus::Writing->value,
                'submittedCounts' => [],
                'votedSeats' => [],
                'answers' => [],
                'results' => null,
            ]];

            $this->usedPromptIds[] = $promptId;

            return;
        }

        $seatGroups = $this->pairingService->generateRoundMatchups(count($this->seats));
        $promptIds = $this->pickPrompts(count($seatGroups));
        $prompts = PunchlinePrompt::whereIn('id', $promptIds)->get()->keyBy('id');

        $this->matchups = [];

        foreach ($seatGroups as $index => $seatIndices) {
            $promptId = $promptIds[$index];
            $this->usedPromptIds[] = $promptId;

            $this->matchups[] = [
                'id' => "r{$this->round}-{$index}",
                'promptId' => $promptId,
                'promptText' => $prompts[$promptId]->text,
                'seatIndices' => $seatIndices,
                'answersPerSeat' => 1,
                'voteLimit' => 1,
                'status' => PunchlineMatchupStatus::Writing->value,
                'submittedCounts' => [],
                'votedSeats' => [],
                'answers' => [],
                'results' => null,
            ];
        }
    }

    /**
     * @return list<int>
     */
    private function pickPrompts(int $count): array
    {
        $ids = PunchlinePrompt::whereNotIn('id', $this->usedPromptIds)
            ->inRandomOrder()
            ->limit($count)
            ->pluck('id')
            ->all();

        if (count($ids) < $count) {
            $ids = array_merge($ids, PunchlinePrompt::whereNotIn('id', $ids)
                ->inRandomOrder()
                ->limit($count - count($ids))
                ->pluck('id')
                ->all());
        }

        return $ids;
    }

    /**
     * Ends the game right now, before round 3 completes on its own --
     * host-only (docs/concerns.md), mirroring pages::farkle.play's own
     * endGameManually(). Reuses finishGame() as-is rather than a separate
     * code path: it already just finalizes GameParticipant rows from
     * whatever $this->scores/pointsByRound/sweepsWon currently hold, which
     * is exactly "stop now, score what's happened so far" -- the same
     * thing round 3's own reveal-to-GameEnd transition in advanceRound()
     * does, just triggered early instead of after the last round.
     */
    public function endGameManually(): void
    {
        abort_unless($this->isHost, 403);

        if ($this->phase === PunchlinePhase::GameEnd) {
            return;
        }

        $this->finishGame();
        $this->phase = PunchlinePhase::GameEnd;
        $this->syncOnlineState();
    }

    private function finishGame(): void
    {
        $session = GameSession::findOrFail($this->gameSessionId);
        $session->update(['ended_at' => now()]);

        // See docs/DEVLOG.md's Farkle "Back to room" fix -- without this,
        // Room.status/game_session_id stay exactly as onRoomStarting() left
        // them, and pages::punchline.room's own redirectIfAlreadyStarted()
        // bounces "Back to room" straight back into this now-finished game.
        $this->resolveRoom()->update(['status' => RoomStatus::Finished]);

        $topScore = $this->scores === [] ? 0 : max($this->scores);
        $seats = RoomSeat::where('room_id', $this->resolveRoom()->id)->get();

        foreach ($seats as $seat) {
            $score = $this->scores[$seat->seat_index] ?? 0;
            $pointsByRound = $this->pointsByRound[$seat->seat_index] ?? [];

            GameParticipant::create([
                'game_session_id' => $session->id,
                'user_id' => $seat->user_id,
                'guest_name' => null,
                'score' => $score,
                'result' => $score === $topScore ? GameResult::Win : GameResult::Loss,
                'stats' => [
                    'pointsByRound' => [
                        $pointsByRound[1] ?? 0,
                        $pointsByRound[2] ?? 0,
                        $pointsByRound[3] ?? 0,
                    ],
                    'sweepsWon' => $this->sweepsWon[$seat->seat_index] ?? 0,
                ],
            ]);
        }
    }
};
?>

<x-slot:title>Punchline — Low Stim Play</x-slot>
<x-slot:description>Write funny answers to prompts, vote on the funniest, and watch the pot swing round to round -- a calm, low-contrast take on party-game prompt battles played in an online room.</x-slot:description>
<x-slot:bodyClass>punchline-play</x-slot:bodyClass>
<x-slot:fullscreenToggle>1</x-slot:fullscreenToggle>
<x-slot:rulesTitle>Punchline Rules</x-slot:rulesTitle>
<x-slot:rules>
    <h3>How to Use This Page</h3>
    <ul>
        <li>Every player joins from their own device -- there's no shared/host screen, since everyone writes their own answers privately.</li>
        <li>Rounds 1 and 2: you're paired with a different opponent for each of your 2 prompts. Write your answer, then vote on the matchups you're not part of.</li>
        <li>Round 3: everyone answers the same prompt 3 times, then everyone votes with 3 votes to spread across other players' answers however they like.</li>
    </ul>

    <h3>Full Rules</h3>
    <ul>
        <li>Each matchup has its own point pool, split between its answers in proportion to the votes each one gets -- a bigger share of the vote wins a bigger share of the pool, not just win-or-lose.</li>
        <li>An answer that gets every single vote in its matchup sweeps -- a bonus on top of its already-full pool share.</li>
        <li>Point pools escalate each round (1000, then 2000, then roughly 3000 in the final round), so a slow start is never out of reach.</li>
        <li>Writing and voting are both timed. Anyone who runs out of time is auto-filled with a placeholder answer -- no points are ever awarded to an absent player.</li>
        <li>The host can press End Game any time to stop early and show final scores; only the host can advance from one round's reveal to the next.</li>
    </ul>
</x-slot:rules>
<div>
    @if ($room !== null)
        <livewire:room-new-game-notice :room-code="$room" :current-game="GameType::Punchline" :my-game-session-id="$gameSessionId" wire:key="room-new-game-notice" />
    @endif

    @if ($started)
        @teleport('#game-actions')
            <div style="display: contents;">
                @if ($this->canEndGameManually)
                    <button type="button" id="end-game-trigger" data-dialog-open="end-game-confirm-dialog">End Game</button>

                    <x-confirm-dialog id="end-game-confirm-dialog" title="End the game?"
                        message="End the game now and show final scores?">
                        <x-slot:confirm>
                            <button type="button" id="end-game-confirm" data-dialog-close wire:click="endGameManually"
                                class="flex-1 rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-warn text-warn">
                                End Game
                            </button>
                        </x-slot:confirm>
                    </x-confirm-dialog>
                @endif

                @if ($phase === PunchlinePhase::GameEnd)
                    <a href="{{ route('room.show', ['code' => $room]) }}"><button type="button">Back to room</button></a>
                @endif
            </div>
        @endteleport
    @endif

    @if ($room === null)
        <div class="setup" id="setup">
            <div class="heading">Punchline</div>

            @if ($this->hostedRooms->isNotEmpty())
                <div class="flex flex-col items-center gap-2 w-full">
                    <p class="status-line">Play in a room you host:</p>
                    <div class="flex flex-wrap justify-center gap-2">
                        @foreach ($this->hostedRooms as $hostedRoom)
                            <a href="{{ route('punchline.room', ['code' => $hostedRoom->code]) }}" wire:key="hosted-room-{{ $hostedRoom->id }}">
                                <button type="button" id="hosted-room-{{ $hostedRoom->code }}">{{ $hostedRoom->code }}</button>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            <p class="status-line">Punchline is online-only -- open <strong>Rooms</strong> in the top bar to create or join a room, then start Punchline from there.</p>
        </div>
    @else
        <div id="punchline-game" class="flex flex-col items-center w-full" style="gap: inherit;">
            @unless ($phase === PunchlinePhase::GameEnd)
                <div class="status-line" id="punchline-round-status">
                    Round {{ $round }} of 3 &middot; Pool {{ $pool }}
                    @if ($this->secondsRemaining !== null)
                        &middot; <span id="punchline-seconds-remaining">{{ $this->secondsRemaining }}s</span> left to {{ $phase === PunchlinePhase::Writing ? 'write' : 'vote' }}
                    @endif
                </div>

                @if (in_array($phase, [PunchlinePhase::Writing, PunchlinePhase::Voting], true))
                    <div wire:poll.2s="checkTimeout"></div>
                @endif

                <div class="flex flex-wrap justify-center gap-3 w-full">
                    @foreach ($seats as $seat)
                        <div wire:key="score-{{ $seat['seatIndex'] }}" class="flex flex-col items-center gap-1 bg-surface border-2 border-surface-border rounded-lg px-4 py-2">
                            <span class="text-sm text-text/80">{{ $seat['name'] }}</span>
                            <span class="text-lg font-bold text-text">{{ $scores[$seat['seatIndex']] ?? 0 }}</span>
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-col items-stretch gap-3 w-full max-w-xl" id="punchline-matchups">
                    @foreach ($matchups as $matchup)
                        <div wire:key="matchup-{{ $matchup['id'] }}" class="panel" id="matchup-{{ $matchup['id'] }}">
                            <div class="panel-title">{{ $matchup['promptText'] }}</div>

                            @if ($matchup['status'] === \App\Enums\PunchlineMatchupStatus::Writing->value)
                                @if ($this->matchupIsAuthor($matchup))
                                    @php $mySlot = $this->matchupMySubmittedCount($matchup); @endphp
                                    @if ($mySlot < $matchup['answersPerSeat'])
                                        <form wire:submit="submitAnswer('{{ $matchup['id'] }}')" class="flex flex-col items-stretch gap-2 w-full">
                                            <input type="text" wire:model="drafts.{{ $matchup['id'] }}-{{ $mySlot }}" id="answer-{{ $matchup['id'] }}-{{ $mySlot }}" maxlength="140" placeholder="Your answer&hellip;" autocomplete="off">
                                            <button type="submit" id="submit-{{ $matchup['id'] }}-{{ $mySlot }}">Submit{{ $matchup['answersPerSeat'] > 1 ? ' ('.($mySlot + 1).'/'.$matchup['answersPerSeat'].')' : '' }}</button>
                                        </form>
                                    @else
                                        <p class="status-line">Waiting for the other player{{ count($matchup['seatIndices']) > 2 ? 's' : '' }} to finish writing&hellip;</p>
                                    @endif
                                @else
                                    <p class="status-line">Writing in progress &mdash; {{ array_sum($matchup['submittedCounts']) }}/{{ count($matchup['seatIndices']) * $matchup['answersPerSeat'] }} answers in.</p>
                                @endif
                            @endif

                            @if ($matchup['status'] === \App\Enums\PunchlineMatchupStatus::Voting->value)
                                @if ($this->matchupCanVote($matchup))
                                    @if ($this->matchupVotesAllowed($matchup) === 1)
                                        <p class="status-line">Vote for the funnier answer:</p>
                                        <div class="controls">
                                            @foreach ($this->matchupVotableAnswers($matchup) as $answer)
                                                <button type="button" wire:key="vote-{{ $answer['answerId'] }}" wire:click="castVote('{{ $matchup['id'] }}', ['{{ $answer['answerId'] }}'])">
                                                    {{ $answer['text'] }}
                                                </button>
                                            @endforeach
                                        </div>
                                    @else
                                        <p class="status-line">You have 3 votes &mdash; spread them across up to 3 answers, or stack them on one.</p>
                                        <div class="flex flex-col items-stretch gap-2 w-full">
                                            @foreach ($this->matchupVotableAnswers($matchup) as $answer)
                                                <div wire:key="allocate-{{ $answer['answerId'] }}" class="flex items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-3 py-2">
                                                    <span class="flex-1 text-text">{{ $answer['text'] }}</span>
                                                    <button type="button" id="downvote-{{ $answer['answerId'] }}" wire:click="adjustVoteAllocation('{{ $answer['answerId'] }}', -1)" aria-label="Remove a vote">&minus;</button>
                                                    <span id="allocation-{{ $answer['answerId'] }}">{{ $voteAllocation[$answer['answerId']] ?? 0 }}</span>
                                                    <button type="button" id="upvote-{{ $answer['answerId'] }}" wire:click="adjustVoteAllocation('{{ $answer['answerId'] }}', 1)" aria-label="Add a vote">+</button>
                                                </div>
                                            @endforeach
                                        </div>
                                        <button type="button" id="cast-round-three-votes" wire:click="submitRoundThreeVotes('{{ $matchup['id'] }}')" @disabled(array_sum($voteAllocation) === 0)>
                                            Cast Votes ({{ array_sum($voteAllocation) }}/3)
                                        </button>
                                    @endif
                                @elseif ($this->matchupHasVoted($matchup))
                                    <p class="status-line">Vote cast &mdash; waiting for the rest of the table&hellip;</p>
                                @else
                                    <p class="status-line">Voting in progress&hellip;</p>
                                    <div class="flex flex-col items-stretch gap-2 w-full">
                                        @foreach ($matchup['answers'] as $answer)
                                            <div wire:key="view-{{ $answer['answerId'] }}" class="bg-surface border-2 border-surface-border rounded-lg px-4 py-2 text-center text-text">{{ $answer['text'] }}</div>
                                        @endforeach
                                    </div>
                                @endif
                            @endif

                            @if ($matchup['status'] === \App\Enums\PunchlineMatchupStatus::Closed->value)
                                <div class="flex flex-col items-stretch gap-2 w-full" id="results-{{ $matchup['id'] }}">
                                    @foreach ($matchup['answers'] as $answer)
                                        @php
                                            $authorSeat = $matchup['results']['authorsByAnswerId'][$answer['answerId']] ?? null;
                                            $points = $matchup['results']['pointsByAnswerId'][$answer['answerId']] ?? 0;
                                            $swept = ($matchup['results']['sweepAnswerId'] ?? null) === $answer['answerId'];
                                        @endphp
                                        <div wire:key="result-{{ $answer['answerId'] }}" class="flex items-center justify-between gap-2 bg-surface border-2 {{ $swept ? 'border-accent' : 'border-surface-border' }} rounded-lg px-4 py-2">
                                            <span class="text-text">{{ $answer['text'] }} &mdash; <span class="text-text/65">{{ $authorSeat !== null ? $this->seatName($authorSeat) : '' }}</span></span>
                                            <span class="font-bold text-text">{{ $points }}{{ $swept ? ' (swept!)' : '' }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endunless

            @if ($phase === PunchlinePhase::RoundReveal)
                <div class="panel">
                    <div class="panel-title">Round {{ $round }} complete</div>
                    @if ($this->isHost)
                        <div class="controls">
                            <button type="button" id="advance-round-btn" wire:click="advanceRound">
                                {{ $round >= 3 ? 'See Final Results' : 'Next Round' }}
                            </button>
                        </div>
                    @elseif ($mySeatIndex !== null)
                        <p class="status-line">Waiting for the host to continue&hellip;</p>
                    @endif
                </div>
            @endif

            @if ($phase === PunchlinePhase::GameEnd)
                <div class="panel">
                    <div class="panel-title">Final Score</div>
                    <div class="panel-title">{{ implode(' & ', $this->winnerNames) }} {{ count($this->winnerNames) > 1 ? 'tie' : 'wins' }}!</div>
                    <div class="flex flex-wrap justify-center gap-3 w-full">
                        @foreach ($seats as $seat)
                            <div wire:key="final-score-{{ $seat['seatIndex'] }}" class="flex flex-col items-center gap-1 bg-surface border-2 border-surface-border rounded-lg px-4 py-2">
                                <span class="text-sm text-text/80">{{ $seat['name'] }}</span>
                                <span class="text-lg font-bold text-text">{{ $scores[$seat['seatIndex']] ?? 0 }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
