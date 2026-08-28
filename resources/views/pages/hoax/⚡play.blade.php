<?php

use App\Enums\GameResult;
use App\Enums\GameType;
use App\Enums\HoaxPhase;
use App\Enums\RoomStatus;
use App\Events\RoomGameStateUpdated;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\HoaxQuestion;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Services\Hoax\AnswerMatcher;
use App\Services\Hoax\FillerAnswers;
use App\Services\Hoax\ScoringService;
use App\Services\SubmitVote\BallotService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    // Shorter than Punchline's 180s/180s -- writing one blank-fill is
    // quicker than a freeform joke, and there are fewer options to read
    // while voting (docs/specs/hoax.md section 2).
    public const int WRITING_SECONDS = 90;

    public const int VOTING_SECONDS = 60;

    public const int ROUND_COUNT = 5;

    /**
     * The online room this play-through belongs to. Hoax has no
     * pass-and-play mode (docs/specs/hoax.md section 1), same reasoning
     * as Punchline -- a bare visit only ever offers a "play in a room you
     * host" list.
     */
    #[Url]
    public ?string $room = null;

    public bool $started = false;

    public ?int $gameSessionId = null;

    public int $round = 1;

    public HoaxPhase $phase = HoaxPhase::Writing;

    public ?int $questionId = null;

    public ?string $questionText = null;

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
     * seatIndex => [round => points], for the per-round breakdown in
     * GameParticipant.stats.
     *
     * @var array<int, array<int, int>>
     */
    public array $pointsByRound = [];

    /**
     * seatIndex => number of rounds this seat correctly picked the real
     * answer.
     *
     * @var array<int, int>
     */
    public array $truthsFound = [];

    public ?int $writingEndsAt = null;

    public ?int $votingEndsAt = null;

    /**
     * @var list<int>
     */
    public array $usedQuestionIds = [];

    /**
     * @var list<int>
     */
    public array $submittedSeats = [];

    /**
     * @var list<int>
     */
    public array $votedSeats = [];

    /**
     * @var list<array{answerId: string, text: string}>
     */
    public array $answers = [];

    /**
     * @var ?array{pointsBySeat: array<int, int>, truthAnswerId: string, authorsByAnswerId: array<string, int>, votesByAnswerId: array<string, int>, seatsWhoFoundTruth: list<int>}
     */
    public ?array $results = null;

    public ?string $message = null;

    /**
     * This connection's seat, or null for an unseated spectator.
     */
    public ?int $mySeatIndex = null;

    /**
     * In-progress, unsubmitted draft text -- unlike Punchline's keyed
     * $drafts array, Hoax only ever has one active question at a time, so
     * a single scalar is enough. Deliberately never included in
     * syncOnlineState()'s $state, same reasoning as Punchline's own.
     */
    public string $draftAnswer = '';

    /**
     * Set only on this connection when submitAnswer() rejects a draft
     * for matching the real answer -- local-only feedback, never synced
     * into $state, same reasoning as $draftAnswer itself.
     */
    public ?string $submitError = null;

    private ScoringService $scoringService;

    private BallotService $ballotService;

    private FillerAnswers $fillerAnswers;

    private AnswerMatcher $answerMatcher;

    public function boot(
        ScoringService $scoringService,
        BallotService $ballotService,
        FillerAnswers $fillerAnswers,
        AnswerMatcher $answerMatcher,
    ): void {
        $this->scoringService = $scoringService;
        $this->ballotService = $ballotService;
        $this->fillerAnswers = $fillerAnswers;
        $this->answerMatcher = $answerMatcher;
    }

    public function mount(): void
    {
        if ($this->room === null) {
            return;
        }

        $roomModel = $this->resolveRoom();
        $session = $roomModel->gameSession;

        if ($session === null || $session->type !== GameType::Hoax) {
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
        $this->phase = HoaxPhase::from($state['phase']);
        $this->questionId = $state['questionId'];
        $this->questionText = $state['questionText'];
        $this->seats = $state['seats'];
        $this->scores = $state['scores'];
        $this->pointsByRound = $state['pointsByRound'];
        $this->truthsFound = $state['truthsFound'];
        $this->writingEndsAt = $state['writingEndsAt'];
        $this->votingEndsAt = $state['votingEndsAt'];
        $this->usedQuestionIds = $state['usedQuestionIds'];
        $this->submittedSeats = $state['submittedSeats'];
        $this->votedSeats = $state['votedSeats'];
        $this->answers = $state['answers'];
        $this->results = $state['results'];
        $this->message = $state['message'];
        $this->started = true;
    }

    /**
     * Re-reads GameSession.state fresh from the database and re-hydrates
     * every property from it -- same reasoning as Punchline's own
     * refreshFromDatabase(), used at the top of submitAnswer()/castVote()
     * since this connection's last-known snapshot can be stale.
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
            'questionId' => $this->questionId,
            'questionText' => $this->questionText,
            'seats' => $this->seats,
            'scores' => $this->scores,
            'pointsByRound' => $this->pointsByRound,
            'truthsFound' => $this->truthsFound,
            'writingEndsAt' => $this->writingEndsAt,
            'votingEndsAt' => $this->votingEndsAt,
            'usedQuestionIds' => $this->usedQuestionIds,
            'submittedSeats' => $this->submittedSeats,
            'votedSeats' => $this->votedSeats,
            'answers' => $this->answers,
            'results' => $this->results,
            'message' => $this->message,
        ];
    }

    private function resolveRoom(): Room
    {
        return Room::where('code', $this->room)->firstOrFail();
    }

    /**
     * Persists + broadcasts the shared round state, same shape as
     * Punchline's own syncOnlineState().
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

    public function getIsHostProperty(): bool
    {
        return Auth::id() === $this->resolveRoom()->host_user_id;
    }

    /**
     * Mirrors pages::punchline.play's own getCanEndGameManuallyProperty()
     * (which itself mirrors pages::farkle.play's), same reasoning.
     */
    public function getCanEndGameManuallyProperty(): bool
    {
        return $this->phase !== HoaxPhase::GameEnd && $this->isHost;
    }

    /**
     * How many seconds remain in the current phase's deadline, or null
     * during a phase with no countdown (RoundReveal/GameEnd).
     */
    public function getSecondsRemainingProperty(): ?int
    {
        $deadline = match ($this->phase) {
            HoaxPhase::Writing => $this->writingEndsAt,
            HoaxPhase::Voting => $this->votingEndsAt,
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

    private function ballotKey(): string
    {
        return "hoax:{$this->gameSessionId}:round:{$this->round}";
    }

    public function getHasSubmittedProperty(): bool
    {
        return $this->mySeatIndex !== null && in_array($this->mySeatIndex, $this->submittedSeats, true);
    }

    public function getCanVoteProperty(): bool
    {
        return $this->mySeatIndex !== null
            && $this->phase === HoaxPhase::Voting
            && ! in_array($this->mySeatIndex, $this->votedSeats, true);
    }

    public function getHasVotedProperty(): bool
    {
        return $this->mySeatIndex !== null && in_array($this->mySeatIndex, $this->votedSeats, true);
    }

    /**
     * $this->answers minus this seat's own answer -- every player is
     * always both an author and a voter every round (unlike Punchline's
     * per-matchup author/voter split), so this exclusion is never
     * optional the way it is for a normal Punchline voter. The real
     * answer's id ("truth") never starts with a seat prefix, so it's
     * never filtered out here.
     *
     * @return list<array{answerId: string, text: string}>
     */
    public function votableAnswers(): array
    {
        if ($this->mySeatIndex === null) {
            return $this->answers;
        }

        return array_values(array_filter(
            $this->answers,
            fn (array $answer) => ! str_starts_with($answer['answerId'], "{$this->mySeatIndex}-"),
        ));
    }

    /**
     * $questionText with its "____" placeholder replaced by $answerText,
     * both HTML-escaped first -- the classic Fibbage reveal, reading as a
     * complete (often absurd) sentence per candidate answer. Returns a
     * pre-escaped HTML string; render with {!! !!}, never {{ }}.
     *
     * The whole sentence is uppercased before display, not just
     * $answerText -- the real answer's casing always comes from the
     * curated seed data (clean, consistent style), while a player's fake
     * answer is typed however they happen to type it (all-lowercase from
     * a phone keyboard, careless capitalization, etc.). Left uneven,
     * that consistent-vs-inconsistent formatting difference would itself
     * become a tell across a whole game, letting an attentive table
     * learn "the cleanly capitalized one is always real" independent of
     * the actual trivia -- uppercasing everything the same way removes
     * that signal without touching how anything is stored or matched.
     * $questionText and $answerText are uppercased separately, before
     * either is wrapped in HTML, so the "____" placeholder (case-
     * invariant) still matches and the <strong> tag itself never gets
     * uppercased into invalid markup.
     */
    public function interpolatedText(string $answerText): string
    {
        return str_replace(
            '____',
            '<strong>'.e(mb_strtoupper($answerText)).'</strong>',
            e(mb_strtoupper($this->questionText)),
        );
    }

    /**
     * Records this seat's one answer, reading the text from
     * $this->draftAnswer. Re-hydrates from the database first, same
     * staleness reasoning as Punchline's own submitAnswer() -- a stale
     * "already left Writing" or "already submitted" is a quiet no-op, not
     * a violation.
     *
     * Rejects (via $submitError, not abort_unless()) a draft that's
     * actually just the real answer or one of its known alternate
     * spellings -- a quiet 200-OK no-op that leaves $draftAnswer in place
     * for the player to edit, not a hard failure. A hard abort here would
     * trip this app's global Livewire error handler into a silent
     * redirect to the homepage on anything other than a deliberate 422,
     * which this app's other games have been burned by before (see
     * Punchline's own staleness-handling docblocks) -- and a genuinely
     * common, non-malicious mistake like "typed the real fact by
     * accident" is exactly the kind of thing that shouldn't ever risk
     * that.
     */
    public function submitAnswer(): void
    {
        abort_unless($this->mySeatIndex !== null, 403);

        $this->refreshFromDatabase();

        $this->submitError = null;

        if ($this->phase !== HoaxPhase::Writing) {
            return;
        }

        if (in_array($this->mySeatIndex, $this->submittedSeats, true)) {
            return;
        }

        $text = trim($this->draftAnswer);
        abort_unless($text !== '', 422);

        $question = HoaxQuestion::find($this->questionId);

        if ($question !== null && $this->answerMatcher->matches($text, $question->answer, $question->alternate_answers ?? [])) {
            $this->submitError = 'That\'s the real answer -- write something else to try to fool people instead.';

            return;
        }

        $this->ballotService->submit($this->ballotKey(), $this->mySeatIndex, "{$this->mySeatIndex}-0", $text);
        $this->submittedSeats[] = $this->mySeatIndex;
        $this->draftAnswer = '';

        if (count($this->submittedSeats) >= count($this->seats)) {
            $this->openVoting();
        }

        $this->syncOnlineState();
    }

    private function openVoting(): void
    {
        $this->answers = $this->ballotService->revealAnonymized($this->ballotKey());
        $this->phase = HoaxPhase::Voting;
        $this->writingEndsAt = null;
        $this->votingEndsAt = now()->addSeconds(self::VOTING_SECONDS)->timestamp;
    }

    /**
     * Records this seat's single vote. Re-hydrates from the database
     * first, same staleness reasoning as Punchline's own castVote().
     * Never votable for your own answer -- votableAnswers() already keeps
     * the real UI from ever offering that button, this is defense in
     * depth only, same pattern as Punchline's own castVote().
     */
    public function castVote(string $answerId): void
    {
        abort_unless($this->mySeatIndex !== null, 403);

        $this->refreshFromDatabase();

        if ($this->phase !== HoaxPhase::Voting || in_array($this->mySeatIndex, $this->votedSeats, true)) {
            return;
        }

        $ballotKey = $this->ballotKey();

        if ($this->ballotService->hasVoted($ballotKey, $this->mySeatIndex)) {
            return;
        }

        $validAnswerIds = array_column($this->answers, 'answerId');
        abort_unless(in_array($answerId, $validAnswerIds, true), 422);

        $authors = $this->ballotService->authors($ballotKey);

        if (($authors[$answerId] ?? null) === $this->mySeatIndex) {
            return;
        }

        $this->ballotService->vote($ballotKey, $this->mySeatIndex, [$answerId]);
        $this->votedSeats[] = $this->mySeatIndex;

        if (count($this->votedSeats) >= count($this->seats)) {
            $this->closeRound();
        }

        $this->syncOnlineState();
    }

    /**
     * Scores the round from its ballot's voter choices, applies points to
     * $scores/$pointsByRound/$truthsFound, then clears the secret ballot
     * state -- the reveal moment. Unlike Punchline's closeMatchup(), this
     * needs BallotService::voterChoices() (per-voter identity), not just
     * tally() -- see that method's own docblock for why Hoax is the
     * exception to "votes stay secret forever."
     */
    private function closeRound(): void
    {
        $ballotKey = $this->ballotKey();

        $voteByVoterSeat = array_map(
            fn (array $answerIds) => $answerIds[0],
            $this->ballotService->voterChoices($ballotKey),
        );
        $authors = $this->ballotService->authors($ballotKey);
        $truthAnswerId = array_search(ScoringService::TRUTH_SEAT_INDEX, $authors, true);

        $result = $this->scoringService->scoreRound($voteByVoterSeat, $authors, $truthAnswerId);

        foreach ($result['pointsBySeat'] as $seatIndex => $points) {
            $this->scores[$seatIndex] = ($this->scores[$seatIndex] ?? 0) + $points;
            $this->pointsByRound[$seatIndex][$this->round] = ($this->pointsByRound[$seatIndex][$this->round] ?? 0) + $points;
        }

        foreach ($result['seatsWhoFoundTruth'] as $seatIndex) {
            $this->truthsFound[$seatIndex] = ($this->truthsFound[$seatIndex] ?? 0) + 1;
        }

        $this->results = [
            'pointsBySeat' => $result['pointsBySeat'],
            'truthAnswerId' => $truthAnswerId,
            'authorsByAnswerId' => $authors,
            'votesByAnswerId' => $result['votesByAnswerId'],
            'seatsWhoFoundTruth' => $result['seatsWhoFoundTruth'],
        ];

        $this->phase = HoaxPhase::RoundReveal;
        $this->votingEndsAt = null;

        $this->ballotService->clear($ballotKey);
    }

    /**
     * Any connected client polls this (see wire:poll below), not just a
     * turn-holder -- same reasoning as Punchline's own checkTimeout().
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

            if ($this->phase === HoaxPhase::Writing && $this->writingEndsAt !== null && $now >= $this->writingEndsAt) {
                $this->applyWritingTimeout();
                $didMutate = true;
            } elseif ($this->phase === HoaxPhase::Voting && $this->votingEndsAt !== null && $now >= $this->votingEndsAt) {
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
     * Auto-fills every still-missing answer from FillerAnswers -- no
     * points are ever awarded for one of these, same reasoning as
     * Punchline's own applyWritingTimeout().
     */
    private function applyWritingTimeout(): void
    {
        $ballotKey = $this->ballotKey();
        $missingSeats = array_values(array_diff(array_column($this->seats, 'seatIndex'), $this->submittedSeats));

        foreach ($missingSeats as $i => $seatIndex) {
            $this->ballotService->submit($ballotKey, $seatIndex, "{$seatIndex}-0", $this->fillerAnswers->pick($i));
            $this->submittedSeats[] = $seatIndex;
        }

        $this->openVoting();
    }

    /**
     * Closes the round as-is once the voting deadline passes -- whatever
     * votes exist so far are scored, same as Punchline's own
     * applyVotingTimeout(); a seat that never voted simply contributes no
     * truth-finding/fooling points, no special-casing needed.
     */
    private function applyVotingTimeout(): void
    {
        $this->closeRound();
    }

    /**
     * Host-only, mirroring pages::punchline.play's own advanceRound().
     */
    public function advanceRound(): void
    {
        abort_unless($this->isHost, 403);

        $this->refreshFromDatabase();

        if ($this->phase !== HoaxPhase::RoundReveal) {
            return;
        }

        if ($this->round >= self::ROUND_COUNT) {
            $this->finishGame();
            $this->phase = HoaxPhase::GameEnd;
            $this->syncOnlineState();

            return;
        }

        $this->round++;
        $this->buildRound();
        $this->phase = HoaxPhase::Writing;
        $this->writingEndsAt = now()->addSeconds(self::WRITING_SECONDS)->timestamp;
        $this->votingEndsAt = null;

        $this->syncOnlineState();
    }

    /**
     * Builds the next round's shared question and re-seeds the real
     * answer into a fresh ballot -- $this->round must already be
     * incremented before this runs, since ballotKey() reads it.
     */
    private function buildRound(): void
    {
        $question = $this->pickQuestion($this->usedQuestionIds);
        $this->usedQuestionIds[] = $question->id;

        $this->questionId = $question->id;
        $this->questionText = $question->text;
        $this->submittedSeats = [];
        $this->votedSeats = [];
        $this->answers = [];
        $this->results = null;

        $this->ballotService->submit($this->ballotKey(), ScoringService::TRUTH_SEAT_INDEX, 'truth', $question->answer);
    }

    private function pickQuestion(array $excludeIds): HoaxQuestion
    {
        $question = HoaxQuestion::whereNotIn('id', $excludeIds)->inRandomOrder()->first();

        return $question ?? HoaxQuestion::inRandomOrder()->firstOrFail();
    }

    /**
     * Ends the game right now, before the last round completes on its
     * own -- host-only, mirroring pages::punchline.play's own
     * endGameManually().
     */
    public function endGameManually(): void
    {
        abort_unless($this->isHost, 403);

        if ($this->phase === HoaxPhase::GameEnd) {
            return;
        }

        $this->finishGame();
        $this->phase = HoaxPhase::GameEnd;
        $this->syncOnlineState();
    }

    private function finishGame(): void
    {
        $session = GameSession::findOrFail($this->gameSessionId);
        $session->update(['ended_at' => now()]);

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
                    'pointsByRound' => array_map(
                        fn (int $round) => $pointsByRound[$round] ?? 0,
                        range(1, self::ROUND_COUNT),
                    ),
                    'truthsFound' => $this->truthsFound[$seat->seat_index] ?? 0,
                ],
            ]);
        }
    }
};
?>

<x-slot:title>Hoax — Low Stim Play</x-slot>
<x-slot:description>Write a convincing fake answer to a trivia question, then vote on which answer -- yours, someone else's, or the real one -- is actually true. Fool other players, or find the truth, in an online room.</x-slot:description>
<x-slot:bodyClass>hoax-play</x-slot:bodyClass>
<x-slot:fullscreenToggle>1</x-slot:fullscreenToggle>
<x-slot:rulesTitle>Hoax Rules</x-slot:rulesTitle>
<x-slot:rules>
    <h3>How to Use This Page</h3>
    <ul>
        <li>Every player joins from their own device -- there's no shared/host screen, since everyone writes their own answer privately.</li>
        <li>Each round shows everyone the same trivia question with a blank. Write a fake answer to fill it in -- the more convincing, the better.</li>
        <li>Once everyone's written, vote on which answer -- including the real one, hidden among the fakes -- you think is actually true. You can't vote for your own.</li>
    </ul>

    <h3>Full Rules</h3>
    <ul>
        <li>Finding the real answer earns you points. Fooling another player with your fake answer also earns you points -- once for every player who fell for it.</li>
        <li>If you accidentally write the real answer, you'll be asked to write something else before you can submit.</li>
        <li>A fixed 5-round game. Writing and voting are both timed; anyone who runs out of time is auto-filled with a placeholder answer, earning no points.</li>
        <li>The host can press End Game any time to stop early and show final scores; only the host can advance from one round's reveal to the next.</li>
    </ul>
</x-slot:rules>
<div>
    @if ($room !== null)
        <livewire:room-new-game-notice :room-code="$room" :current-game="GameType::Hoax" :my-game-session-id="$gameSessionId" wire:key="room-new-game-notice" />
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

                @if ($phase === HoaxPhase::GameEnd)
                    <a href="{{ route('room.show', ['code' => $room]) }}"><button type="button">Back to room</button></a>
                @endif
            </div>
        @endteleport
    @endif

    @if ($room === null)
        <div class="setup" id="setup">
            <div class="heading">Hoax</div>

            @if ($this->hostedRooms->isNotEmpty())
                <div class="flex flex-col items-center gap-2 w-full">
                    <p class="status-line">Play in a room you host:</p>
                    <div class="flex flex-wrap justify-center gap-2">
                        @foreach ($this->hostedRooms as $hostedRoom)
                            <a href="{{ route('hoax.room', ['code' => $hostedRoom->code]) }}" wire:key="hosted-room-{{ $hostedRoom->id }}">
                                <button type="button" id="hosted-room-{{ $hostedRoom->code }}">{{ $hostedRoom->code }}</button>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            <p class="status-line">Hoax is online-only -- open <strong>Rooms</strong> in the top bar to create or join a room, then start Hoax from there.</p>
        </div>
    @else
        <div id="hoax-game" class="flex flex-col items-center w-full" style="gap: inherit;">
            @unless ($phase === HoaxPhase::GameEnd)
                <div class="status-line" id="hoax-round-status">
                    Round {{ $round }} of 5
                    @if ($this->secondsRemaining !== null)
                        &middot; <span id="hoax-seconds-remaining">{{ $this->secondsRemaining }}s</span> left to {{ $phase === HoaxPhase::Writing ? 'write' : 'vote' }}
                    @endif
                </div>

                @if (in_array($phase, [HoaxPhase::Writing, HoaxPhase::Voting], true))
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

                <div class="panel w-full max-w-xl">
                    <div class="panel-title">{{ $questionText }}</div>

                    @if ($phase === HoaxPhase::Writing)
                        @if ($mySeatIndex === null)
                            <p class="status-line">Spectating &mdash; {{ count($submittedSeats) }}/{{ count($seats) }} answers in.</p>
                        @elseif ($this->hasSubmitted)
                            <p class="status-line">Waiting for the other players to finish writing&hellip; ({{ count($submittedSeats) }}/{{ count($seats) }})</p>
                        @else
                            <form wire:submit="submitAnswer" class="flex flex-col items-stretch gap-2 w-full">
                                <input type="text" wire:model="draftAnswer" id="hoax-answer-input" maxlength="140" placeholder="Your fake answer&hellip;" autocomplete="off">
                                @if ($submitError)
                                    <p class="status-line" id="hoax-submit-error" style="color: var(--warn);">{{ $submitError }}</p>
                                @endif
                                <button type="submit" id="hoax-submit-btn">Submit</button>
                            </form>
                        @endif
                    @endif

                    @if ($phase === HoaxPhase::Voting)
                        @if ($mySeatIndex === null)
                            <p class="status-line">Spectating &mdash; {{ count($votedSeats) }}/{{ count($seats) }} votes in.</p>
                        @elseif ($this->canVote)
                            <p class="status-line">Which answer is the real one?</p>
                            <div class="flex flex-col items-stretch gap-2 w-full">
                                @foreach ($this->votableAnswers() as $answer)
                                    <button type="button" wire:key="vote-{{ $answer['answerId'] }}" wire:click="castVote('{{ $answer['answerId'] }}')" class="text-left">
                                        {!! $this->interpolatedText($answer['text']) !!}
                                    </button>
                                @endforeach
                            </div>
                        @elseif ($this->hasVoted)
                            <p class="status-line">Vote cast &mdash; waiting for the rest of the table&hellip; ({{ count($votedSeats) }}/{{ count($seats) }})</p>
                        @endif
                    @endif
                </div>
            @endunless

            @if ($phase === HoaxPhase::RoundReveal && $results !== null)
                <div class="panel w-full max-w-xl">
                    <div class="panel-title">Round {{ $round }} complete</div>

                    <div class="flex flex-col items-stretch gap-2 w-full">
                        @foreach ($answers as $answer)
                            @php
                                $isTruth = $answer['answerId'] === $results['truthAnswerId'];
                                $authorSeat = $results['authorsByAnswerId'][$answer['answerId']] ?? null;
                                $votes = $results['votesByAnswerId'][$answer['answerId']] ?? 0;
                            @endphp
                            <div wire:key="reveal-{{ $answer['answerId'] }}" class="flex flex-col gap-1 bg-surface border-2 {{ $isTruth ? 'border-accent' : 'border-surface-border' }} rounded-lg px-4 py-2">
                                <span class="text-text">{!! $this->interpolatedText($answer['text']) !!}</span>
                                <span class="text-sm text-text/65">
                                    @if ($isTruth)
                                        The real answer &mdash; {{ $votes }} {{ $votes === 1 ? 'player' : 'players' }} found it.
                                    @else
                                        Written by {{ $this->seatName($authorSeat) }} &mdash; fooled {{ $votes }} {{ $votes === 1 ? 'player' : 'players' }}.
                                    @endif
                                </span>
                            </div>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap justify-center gap-3 w-full">
                        @foreach ($seats as $seat)
                            <div wire:key="round-points-{{ $seat['seatIndex'] }}" class="flex flex-col items-center gap-1 bg-surface border-2 border-surface-border rounded-lg px-4 py-2">
                                <span class="text-sm text-text/80">{{ $seat['name'] }}</span>
                                <span class="text-lg font-bold text-text">+{{ $results['pointsBySeat'][$seat['seatIndex']] ?? 0 }}</span>
                            </div>
                        @endforeach
                    </div>

                    @if ($this->isHost)
                        <div class="controls">
                            <button type="button" id="advance-round-btn" wire:click="advanceRound">
                                {{ $round >= 5 ? 'See Final Results' : 'Next Round' }}
                            </button>
                        </div>
                    @elseif ($mySeatIndex !== null)
                        <p class="status-line">Waiting for the host to continue&hellip;</p>
                    @endif
                </div>
            @endif

            @if ($phase === HoaxPhase::GameEnd)
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
