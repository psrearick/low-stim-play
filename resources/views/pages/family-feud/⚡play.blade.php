<?php

use App\Enums\FamilyFeudPhase;
use App\Enums\GameMode;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Events\RoomGameStateUpdated;
use App\Models\FamilyFeudQuestion;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Services\FamilyFeud\JudgeService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    public const MIN_ROUNDS = 1;

    public const DEFAULT_ROUNDS = 5;

    /**
     * @var array{0: string, 1: string}
     */
    public array $teamNames = ['Team 1', 'Team 2'];

    public int $rounds = self::DEFAULT_ROUNDS;

    public bool $started = false;

    /**
     * @var list<array{name: string, score: int}>
     */
    public array $teams = [];

    /**
     * The client-safe shape only (id/prompt/answerCount) -- never the
     * hidden answers. See the class-level note below on why answers are
     * never held as a public property at all.
     *
     * @var list<array{id: int, prompt: string, answerCount: int}>
     */
    public array $questions = [];

    public int $currentQuestionIndex = 0;

    /**
     * Revealed answers for the *current* question only, keyed by index.
     * Legitimately safe to expose once revealed.
     *
     * @var array<int, array{text: string, points: int}>
     */
    public array $revealed = [];

    public int $strikes = 0;

    public int $pot = 0;

    public int $controllingTeam = 0;

    public FamilyFeudPhase $phase = FamilyFeudPhase::Play;

    /**
     * Points each team earned per round, e.g. roundHistory[0] = [0, 27].
     *
     * @var list<array{0: int, 1: int}>
     */
    public array $roundHistory = [];

    public string $guessInput = '';

    /**
     * The last guess that failed to reach the judge, kept only so Retry
     * can resubmit it without the host retyping it.
     */
    public ?string $pendingGuess = null;

    public ?string $message = null;

    public bool $messageIsError = false;

    public bool $turnOptionsOpen = false;

    public string $roundEndTitle = '';

    /**
     * Which local team, if any, is the authenticated account holder --
     * null means "not attributing this game to my account." Only offered
     * when logged in; drives user_id vs guest_name in endGame().
     */
    public ?int $meTeamIndex = null;

    /**
     * The online room this play-through belongs to, if any -- mirrors
     * pages::yahtzee.play's own `$room` exactly. When set, `teams`/
     * `questions`/`revealed`/etc. are all hydrated from the room's shared
     * GameSession (see mount()) instead of running local setup, every
     * mutating action is gated to whoever's actually allowed to act right
     * now, and every successful mutation persists + broadcasts the new
     * state so every other seat/spectator stays in sync.
     */
    #[Url]
    public ?string $room = null;

    public ?int $gameSessionId = null;

    /**
     * This connection's seat in the online room, or null for local
     * pass-and-play or an unseated spectator -- same meaning as
     * pages::yahtzee.play's own mySeatIndex.
     */
    public ?int $mySeatIndex = null;

    public ?int $myTeamIndex = null;

    public bool $isCaptain = false;

    /**
     * Per team, in turn order: [['seatIndex'=>.., 'name'=>.., 'isCaptain'=>..], ...].
     * Online only -- see docs/DEVLOG.md for the alternating join-order
     * assignment (even seat index -> team 0, odd -> team 1, first seat on
     * each team is captain).
     *
     * @var list<list<array{seatIndex: int, name: string, isCaptain: bool}>>
     */
    public array $teamSeats = [[], []];

    /**
     * Index into each team's own teamSeats array -- whose turn it is
     * within that team to submit the next guess. Rotates by one (wrapping)
     * every time that team submits a resolved guess during Play; untouched
     * by Steal, which is always the stealing team's captain regardless of
     * this pointer. Analogous to Yahtzee's own currentSeat, but per-team
     * rather than a single shared pointer.
     *
     * @var array{0: int, 1: int}
     */
    public array $teamTurnPointer = [0, 0];

    public function mount(): void
    {
        // maxRounds() floors to MIN_ROUNDS even when the bank has zero
        // questions -- it's a *display* bound for the stepper, not a
        // promise that many rounds actually exist. Clamping the starting
        // value here just keeps the stepper's initial display honest;
        // startGame() below is what actually guards against playing with
        // too few (or no) questions.
        $this->rounds = min($this->rounds, $this->maxRounds());

        if ($this->room === null) {
            return;
        }

        $roomModel = $this->resolveRoom();
        $session = $roomModel->gameSession;

        $this->gameSessionId = $session->id;
        $this->hydrateFromState($session->state);

        $seat = RoomSeat::where('room_id', $roomModel->id)->where('user_id', Auth::id())->first();
        $this->mySeatIndex = $seat?->seat_index;
        $this->myTeamIndex = $seat?->team_index;
        $this->isCaptain = $seat?->is_captain ?? false;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function hydrateFromState(array $state): void
    {
        $this->teams = $state['teams'];
        $this->teamSeats = $state['teamSeats'];
        $this->teamTurnPointer = $state['teamTurnPointer'];
        $this->questions = $state['questions'];
        $this->currentQuestionIndex = $state['currentQuestionIndex'];
        $this->revealed = $state['revealed'];
        $this->strikes = $state['strikes'];
        $this->pot = $state['pot'];
        $this->controllingTeam = $state['controllingTeam'];
        $this->phase = FamilyFeudPhase::from($state['phase']);
        $this->roundHistory = $state['roundHistory'];
        $this->roundEndTitle = $state['roundEndTitle'];
        $this->message = $state['message'];
        $this->messageIsError = $state['messageIsError'];
        $this->started = true;
    }

    private function resolveRoom(): Room
    {
        return Room::where('code', $this->room)->firstOrFail();
    }

    /**
     * Persists the current shared state onto the room's GameSession and
     * broadcasts it to every other seat/spectator -- see
     * pages::yahtzee.play's own syncOnlineState() for why this is wrapped
     * in a row-locked transaction (cheap insurance against a single seat's
     * own double-tap/retry racing itself, not a full concurrency solution).
     */
    private function syncOnlineState(): void
    {
        $state = [
            'teams' => $this->teams,
            'teamSeats' => $this->teamSeats,
            'teamTurnPointer' => $this->teamTurnPointer,
            'questions' => $this->questions,
            'currentQuestionIndex' => $this->currentQuestionIndex,
            'revealed' => $this->revealed,
            'strikes' => $this->strikes,
            'pot' => $this->pot,
            'controllingTeam' => $this->controllingTeam,
            'phase' => $this->phase->value,
            'roundHistory' => $this->roundHistory,
            'roundEndTitle' => $this->roundEndTitle,
            'message' => $this->message,
            'messageIsError' => $this->messageIsError,
        ];

        DB::transaction(function () use ($state) {
            GameSession::where('id', $this->gameSessionId)->lockForUpdate()->first();
            GameSession::find($this->gameSessionId)->update(['state' => $state]);
        });

        RoomGameStateUpdated::dispatch($this->resolveRoom(), $state);
    }

    private function syncOnlineStateIfNeeded(): void
    {
        if ($this->room !== null) {
            $this->syncOnlineState();
        }
    }

    /**
     * The Echo callback for a custom broadcast event delivers the whole
     * broadcastWith() payload as one positional argument -- matches
     * pages::yahtzee.play's own onStateUpdated() for the same reason.
     *
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

    /**
     * setPlayerCount-style stepper for the setup screen, bounded by how
     * many questions are actually in the bank (each round consumes 2).
     */
    public function setRounds(int $rounds): void
    {
        $this->rounds = max(self::MIN_ROUNDS, min($this->maxRounds(), $rounds));
    }

    public function maxRounds(): int
    {
        return max(self::MIN_ROUNDS, intdiv(FamilyFeudQuestion::count(), 2));
    }

    public function startGame(): void
    {
        // A fresh/misconfigured deployment (seeder never run) or a bank
        // that's simply been emptied out since leaves nothing to deal --
        // without this, $questions ended up [], and the very next render
        // (currentQuestion()) crashed with a fatal "Undefined array key 0"
        // instead of a readable error.
        if (FamilyFeudQuestion::count() === 0) {
            $this->message = 'No Survey Says questions are available yet -- the question bank needs to be seeded.';
            $this->messageIsError = true;

            return;
        }

        $chosen = FamilyFeudQuestion::inRandomOrder()
            ->limit($this->rounds * 2)
            ->get()
            ->map(fn (FamilyFeudQuestion $question) => $question->toSafeArray())
            ->all();

        $this->teams = [
            ['name' => $this->resolvedTeamName(0), 'score' => 0],
            ['name' => $this->resolvedTeamName(1), 'score' => 0],
        ];
        $this->questions = $chosen;
        $this->currentQuestionIndex = 0;
        $this->revealed = [];
        $this->strikes = 0;
        $this->pot = 0;
        $this->controllingTeam = 0;
        $this->phase = FamilyFeudPhase::Play;
        $this->roundHistory = array_fill(0, $this->rounds, [0, 0]);
        $this->started = true;
        $this->message = null;
        $this->pendingGuess = null;
    }

    private function resolvedTeamName(int $index): string
    {
        $typed = trim($this->teamNames[$index] ?? '');

        return $typed !== '' ? $typed : 'Team '.($index + 1);
    }

    public function submitGuess(JudgeService $judge): void
    {
        abort_unless($this->room === null || $this->isMyTurnValue(), 403);

        $guess = trim($this->guessInput);

        if ($guess === '') {
            return;
        }

        $this->guessInput = '';
        $this->judgeGuess($judge, $guess);
    }

    public function retryGuess(JudgeService $judge): void
    {
        abort_unless($this->room === null || $this->isMyTurnValue(), 403);

        if ($this->pendingGuess === null) {
            return;
        }

        $this->judgeGuess($judge, $this->pendingGuess);
    }

    private function judgeGuess(JudgeService $judge, string $guess): void
    {
        $question = FamilyFeudQuestion::find($this->currentQuestion()['id']);
        $result = $judge->judge($question->answers, $guess);

        if ($result === null) {
            // Local to this device only, not synced -- see pendingGuess's
            // own docblock. Only the device whose guess actually failed to
            // reach the judge needs to see the Retry option.
            $this->pendingGuess = $guess;
            $this->message = "Couldn't reach the judge.";
            $this->messageIsError = true;

            return;
        }

        $this->pendingGuess = null;

        // Captured before handleJudgeResult() runs, since a match on the
        // third strike flips phase from Play to Steal inside it -- the
        // pointer only ever rotates for a Play-phase guess, never a Steal
        // attempt (which is always the captain's, regardless of pointer).
        $wasPlayPhase = $this->room !== null && $this->phase === FamilyFeudPhase::Play;

        $this->handleJudgeResult($result);

        if ($wasPlayPhase) {
            $this->advanceTeamTurnPointer($this->controllingTeam);
        }

        $this->syncOnlineStateIfNeeded();
    }

    /**
     * @param  array{matchIndex: int|null, text?: string, points?: int}  $result
     */
    private function handleJudgeResult(array $result): void
    {
        $matchIndex = $result['matchIndex'];
        $isNewMatch = $matchIndex !== null && ! isset($this->revealed[$matchIndex]);

        if ($this->phase === FamilyFeudPhase::Play) {
            if ($isNewMatch) {
                $this->revealed[$matchIndex] = ['text' => $result['text'], 'points' => $result['points']];
                $this->pot += $result['points'];
                $this->message = "Match! {$result['text']} ({$result['points']} pts)";
                $this->messageIsError = false;

                if (count($this->revealed) === $this->currentQuestion()['answerCount']) {
                    $this->roundEnd($this->controllingTeam);
                }
            } elseif ($matchIndex !== null) {
                $this->message = 'Already up there.';
                $this->messageIsError = false;
            } else {
                $this->strikes++;
                $this->message = "No match. Strike {$this->strikes}.";
                $this->messageIsError = false;

                if ($this->strikes >= 3) {
                    $this->phase = FamilyFeudPhase::Steal;
                }
            }
        } elseif ($this->phase === FamilyFeudPhase::Steal) {
            $otherTeam = 1 - $this->controllingTeam;

            if ($isNewMatch) {
                $this->revealed[$matchIndex] = ['text' => $result['text'], 'points' => $result['points']];
                $this->message = "Steal! {$result['text']} ({$result['points']} pts)";
                $this->messageIsError = false;
                $this->roundEnd($otherTeam);
            } else {
                $this->message = 'No steal.';
                $this->messageIsError = false;
                $this->roundEnd($this->controllingTeam);
            }
        }
    }

    private function roundEnd(int $winningTeam): void
    {
        $this->teams[$winningTeam]['score'] += $this->pot;
        $this->recordRoundPoints($winningTeam, $this->pot);
        $this->phase = FamilyFeudPhase::RoundEnd;
        $this->roundEndTitle = "{$this->teams[$winningTeam]['name']} win the pot! (+{$this->pot})";

        // No network round-trip needed to reveal the rest (unlike the
        // legacy version's Netlify function) -- we already have direct,
        // synchronous access to the same database.
        $question = FamilyFeudQuestion::find($this->currentQuestion()['id']);

        foreach ($question->answers as $i => $answer) {
            $this->revealed[$i] ??= ['text' => $answer['text'], 'points' => $answer['points']];
        }
    }

    private function recordRoundPoints(int $winningTeam, int $points): void
    {
        $roundIndex = intdiv($this->currentQuestionIndex, 2);
        $this->roundHistory[$roundIndex][$winningTeam] += $points;
    }

    public function nextRound(): void
    {
        abort_unless($this->room === null || $this->mySeatIndex !== null, 403);

        if ($this->currentQuestionIndex % 2 === 0) {
            $this->advanceQuestion();
        } else {
            $this->phase = FamilyFeudPhase::RoundSummary;
        }

        $this->syncOnlineStateIfNeeded();
    }

    public function nextRoundSummary(): void
    {
        abort_unless($this->room === null || $this->mySeatIndex !== null, 403);

        $this->advanceQuestion();
        $this->syncOnlineStateIfNeeded();
    }

    private function advanceQuestion(): void
    {
        $this->turnOptionsOpen = false;
        $this->currentQuestionIndex++;

        if ($this->currentQuestionIndex >= count($this->questions)) {
            $this->endGame();

            return;
        }

        $this->revealed = [];
        $this->strikes = 0;
        $this->pot = 0;
        $this->controllingTeam = $this->currentQuestionIndex % 2;
        $this->phase = FamilyFeudPhase::Play;
        $this->message = null;
    }

    public function skipQuestion(): void
    {
        abort_unless($this->room === null || $this->isMyTurnValue(), 403);

        $usedIds = collect($this->questions)->pluck('id');
        $replacement = FamilyFeudQuestion::whereNotIn('id', $usedIds)->inRandomOrder()->first();

        if (! $replacement) {
            $this->message = 'No spare questions left in the bank to skip to.';
            $this->messageIsError = true;

            return;
        }

        $this->questions[$this->currentQuestionIndex] = $replacement->toSafeArray();
        $this->revealed = [];
        $this->strikes = 0;
        $this->pot = 0;
        $this->phase = FamilyFeudPhase::Play;
        $this->turnOptionsOpen = false;
        $this->message = null;

        $this->syncOnlineStateIfNeeded();
    }

    public function swapControl(): void
    {
        abort_unless($this->room === null || $this->isMyTurnValue(), 403);

        $this->controllingTeam = 1 - $this->controllingTeam;
        $this->turnOptionsOpen = false;

        $this->syncOnlineStateIfNeeded();
    }

    public function toggleTurnOptions(): void
    {
        $this->turnOptionsOpen = ! $this->turnOptionsOpen;
    }

    public function revealManually(): void
    {
        abort_unless($this->room === null || $this->mySeatIndex !== null, 403);

        $question = FamilyFeudQuestion::find($this->currentQuestion()['id']);

        foreach ($question->answers as $i => $answer) {
            $this->revealed[$i] = ['text' => $answer['text'], 'points' => $answer['points']];
        }

        $this->phase = FamilyFeudPhase::Manual;
        $this->message = null;

        $this->syncOnlineStateIfNeeded();
    }

    public function awardManual(int $teamIndex): void
    {
        abort_unless($this->room === null || $this->mySeatIndex !== null, 403);

        $this->teams[$teamIndex]['score'] += $this->pot;
        $this->recordRoundPoints($teamIndex, $this->pot);
        $this->pot = 0;
        $this->phase = FamilyFeudPhase::RoundEnd;
        $this->roundEndTitle = "{$this->teams[$teamIndex]['name']} take the round.";

        $this->syncOnlineStateIfNeeded();
    }

    public function endGame(): void
    {
        abort_unless($this->room === null || $this->mySeatIndex !== null, 403);

        if ($this->phase === FamilyFeudPhase::GameEnd) {
            return;
        }

        $this->phase = FamilyFeudPhase::GameEnd;

        $topScore = max($this->teams[0]['score'], $this->teams[1]['score']);
        $tie = $this->teams[0]['score'] === $this->teams[1]['score'];

        if ($this->room !== null) {
            // The GameSession already exists (pages::family-feud.room's
            // onRoomStarting() created it when the game started) -- just
            // mark it ended, rather than creating a second one. Mirrors
            // pages::yahtzee.play's own endGame().
            $session = GameSession::findOrFail($this->gameSessionId);
            $session->update(['ended_at' => now()]);

            // See docs/DEVLOG.md's Farkle "Back to room" fix -- without
            // this, Room.status/game_session_id stay exactly as
            // startGame() left them, and pages::family-feud.room's own
            // redirectIfAlreadyStarted() bounces "Back to room" straight
            // back into this now-finished game instead of the lobby.
            $this->resolveRoom()->update(['status' => RoomStatus::Finished]);

            // Every seat is a real (if possibly guest) account -- one
            // GameParticipant per seat (not per team), each carrying its
            // own team's final score/result. Unlike local mode, there's no
            // meTeamIndex-guessing needed: every seat's user_id is known
            // for certain, so every real account that played gets
            // correctly attributed stats, not just one "which team is
            // you" pick.
            $seats = RoomSeat::where('room_id', $this->resolveRoom()->id)->get();

            foreach ($seats as $seat) {
                $team = $this->teams[$seat->team_index];

                $result = match (true) {
                    $tie => GameResult::Draw,
                    $team['score'] === $topScore => GameResult::Win,
                    default => GameResult::Loss,
                };

                GameParticipant::create([
                    'game_session_id' => $session->id,
                    'user_id' => $seat->user_id,
                    'guest_name' => null,
                    'score' => $team['score'],
                    'result' => $result,
                    'stats' => [
                        'team_index' => $seat->team_index,
                        'is_captain' => $seat->is_captain,
                        'round_history' => array_column($this->roundHistory, $seat->team_index),
                    ],
                ]);
            }
        } else {
            $session = GameSession::create([
                'type' => GameType::FamilyFeud,
                'mode' => GameMode::Local,
                'settings' => ['rounds' => count($this->roundHistory)],
                'started_at' => now(),
                'ended_at' => now(),
            ]);

            foreach ($this->teams as $i => $team) {
                $result = match (true) {
                    $tie => GameResult::Draw,
                    $team['score'] === $topScore => GameResult::Win,
                    default => GameResult::Loss,
                };

                // Pass-and-play has no way to know which local team is the
                // account holder in general (see docs/CHANGELOG.md) --
                // $meTeamIndex is the host explicitly declaring it during
                // setup, the same way Boggle's own solo games attribute
                // user_id vs guest_name.
                $isMe = $i === $this->meTeamIndex && Auth::check();

                GameParticipant::create([
                    'game_session_id' => $session->id,
                    'user_id' => $isMe ? Auth::id() : null,
                    'guest_name' => $isMe ? null : $team['name'],
                    'score' => $team['score'],
                    'result' => $result,
                    'stats' => [
                        'team_index' => $i,
                        'is_captain' => false,
                        'round_history' => array_column($this->roundHistory, $i),
                    ],
                ]);
            }
        }

        $this->syncOnlineStateIfNeeded();
    }

    public function newGame(): void
    {
        // Hidden in Blade for online games too (see the room's own "Back
        // to room" link instead) -- resetting to local setup would orphan
        // the shared room. Guarded here as well in case of a stale client,
        // mirrors pages::yahtzee.play's own newGame().
        if ($this->room !== null) {
            return;
        }

        $this->started = false;
        $this->teams = [];
        $this->questions = [];
        $this->message = null;
        $this->pendingGuess = null;
        $this->meTeamIndex = null;
    }

    /**
     * @return array{id: int, prompt: string, answerCount: int}
     */
    public function currentQuestion(): array
    {
        return $this->questions[$this->currentQuestionIndex];
    }

    public function turnTeam(): int
    {
        return $this->phase === FamilyFeudPhase::Steal ? 1 - $this->controllingTeam : $this->controllingTeam;
    }

    public function roundNumber(): int
    {
        return intdiv($this->currentQuestionIndex, 2) + 1;
    }

    public function totalRounds(): int
    {
        return intdiv(count($this->questions), 2);
    }

    public function winnerText(): string
    {
        if ($this->teams[0]['score'] === $this->teams[1]['score']) {
            return "It's a tie!";
        }

        $winner = $this->teams[0]['score'] > $this->teams[1]['score'] ? $this->teams[0] : $this->teams[1];

        return "{$winner['name']} wins!";
    }

    public function getRoundIndicatorProperty(): string
    {
        if ($this->phase === FamilyFeudPhase::GameEnd) {
            return 'Game over';
        }

        $turnTeam = $this->turnTeam();

        $suffix = match ($this->phase) {
            FamilyFeudPhase::Steal => ' — Steal: '.$this->teams[$turnTeam]['name'],
            FamilyFeudPhase::Play => ' — '.$this->teams[$turnTeam]['name']."'s turn",
            default => '',
        };

        return 'Round '.$this->roundNumber().' of '.$this->totalRounds().$suffix;
    }

    public function getInPlayProperty(): bool
    {
        return $this->phase === FamilyFeudPhase::Play || $this->phase === FamilyFeudPhase::Steal;
    }

    /**
     * The seat currently allowed to submit a guess, or null when no single
     * seat "owns" the current phase (RoundEnd/RoundSummary/Manual/GameEnd
     * are gated to "any seated player" instead -- see endGame()/
     * nextRound()/etc.). During Steal it's always the *stealing* team's
     * captain, regardless of the turn pointer; during Play it's whoever
     * the controlling team's own pointer currently lands on.
     */
    private function currentActingSeatIndex(): ?int
    {
        return match ($this->phase) {
            FamilyFeudPhase::Steal => $this->captainSeatIndex(1 - $this->controllingTeam),
            FamilyFeudPhase::Play => $this->turnPointerSeatIndex($this->controllingTeam),
            default => null,
        };
    }

    private function captainSeatIndex(int $teamIndex): ?int
    {
        $captain = collect($this->teamSeats[$teamIndex] ?? [])->firstWhere('isCaptain', true);

        return $captain['seatIndex'] ?? null;
    }

    private function turnPointerSeatIndex(int $teamIndex): ?int
    {
        $seats = $this->teamSeats[$teamIndex] ?? [];

        if ($seats === []) {
            return null;
        }

        $pointer = $this->teamTurnPointer[$teamIndex] % count($seats);

        return $seats[$pointer]['seatIndex'];
    }

    private function advanceTeamTurnPointer(int $teamIndex): void
    {
        $seatCount = count($this->teamSeats[$teamIndex] ?? []);

        if ($seatCount <= 1) {
            return;
        }

        $this->teamTurnPointer[$teamIndex] = ($this->teamTurnPointer[$teamIndex] + 1) % $seatCount;
    }

    /**
     * The display name of whoever currentActingSeatIndex() resolves to,
     * for the "Waiting for {name}..." line shown to everyone else. Null
     * when no single seat owns the current phase, or locally (no seats).
     */
    public function turnHolderName(): ?string
    {
        $seatIndex = $this->currentActingSeatIndex();

        if ($seatIndex === null) {
            return null;
        }

        foreach ($this->teamSeats as $seats) {
            foreach ($seats as $seat) {
                if ($seat['seatIndex'] === $seatIndex) {
                    return $seat['name'];
                }
            }
        }

        return null;
    }

    /**
     * Always true for local pass-and-play (no seat to check); for an
     * online room, true only for the seat actually allowed to guess right
     * now. Mirrors pages::yahtzee.play's own isMyTurn exactly.
     *
     * Private and uncached deliberately -- see pages::farkle.play's own
     * identical split (canRollAgainValue()/canBankValue() etc.) for the
     * full story. Livewire's legacy getXxxProperty() convention memoizes
     * a property's value the first time it's read within a request.
     * submitGuess()/retryGuess()/skipQuestion()/swapControl() all used to
     * read $this->isMyTurn as their own guard *before* mutating
     * $phase/$controllingTeam/$teamTurnPointer -- exactly the state
     * currentActingSeatIndex() depends on -- caching the pre-mutation
     * answer. Blade's later render of $this->isMyTurn (the public getter
     * below) then silently reused that stale value instead of
     * recomputing against the new phase/pointer/controlling team.
     * Concretely: right after tapping "Swap Team," the tapper's own
     * screen kept showing the guess form instead of switching to
     * "Waiting for {other team}...", since isMyTurn was cached true
     * before controllingTeam flipped. Every action now calls this
     * private, always-fresh version instead; the public getter is only
     * ever read by Blade, so its first (and only) cache population per
     * request is always post-mutation.
     */
    private function isMyTurnValue(): bool
    {
        return $this->room === null || $this->mySeatIndex === $this->currentActingSeatIndex();
    }

    public function getIsMyTurnProperty(): bool
    {
        return $this->isMyTurnValue();
    }

    public function getTurnStatusProperty(): string
    {
        if ($this->room === null || ! $this->inPlay) {
            return '';
        }

        $name = $this->turnHolderName();

        if ($name === null) {
            return '';
        }

        return $this->phase === FamilyFeudPhase::Steal
            ? "{$name} — steal attempt"
            : "{$name}'s turn to guess";
    }
};
?>

<x-slot:title>Survey Says — Low Stim Play</x-slot>
<x-slot:description>A single-screen Survey Says style game for family game night. An LLM judges guesses server-side, so the whole family plays from one shared, calm, low-contrast screen.</x-slot>
<x-slot:bodyClass>family-feud-play</x-slot:bodyClass>
<x-slot:fullscreenToggle>1</x-slot:fullscreenToggle>
<x-slot:rulesTitle>Survey Says Rules</x-slot:rulesTitle>
<x-slot:rules>
    <h3>How to Use This Page</h3>
    <ul>
        <li>One person is the host and types on this screen; everyone else just calls out answers out loud (in person or over a video call, with this screen shared).</li>
        <li>The answers for each question are hidden from everyone, including the host — an AI judge checks each guess against the hidden list and reveals a tile only when it finds a match.</li>
        <li>Type whatever a player calls out and press Submit (or Enter). "Checking..." means the judge is thinking; the input is disabled until it answers.</li>
        <li>Tap <strong>Options</strong> below the guess box to reveal Skip Question and Swap Team -- they're tucked away since you'd only need them once, right at the start of a turn.</li>
    </ul>

    <h3>Full Rules</h3>
    <ul>
        <li>Two teams. Every round, both teams get a turn on their own question — Team 1 goes first, then Team 2 — so an odd number of rounds never gives either team an extra turn. No face-off buzzer round.</li>
        <li>On the controlling team's turn, every correct new answer reveals its tile and adds its points to the pot. A wrong guess adds a strike.</li>
        <li>Guessing something already revealed doesn't count as a strike — it's just shown as "already up there."</li>
        <li>Three strikes hands one steal guess to the other team. A match on the steal wins the whole pot for the stealing team; a miss lets the controlling team keep it.</li>
        <li>Once every tile is revealed (or the steal is resolved), that team's turn ends, the pot is awarded, and any remaining answers are shown before moving on to the other team's turn.</li>
        <li>Once both teams have taken their turn, a round summary shows how many points each team earned that round before moving to the next one.</li>
        <li>Don't like the current question, or already played it before? <strong>Skip Question</strong> (under Options) swaps in a different question for the same turn — no scoring change.</li>
        <li>If the judge is unreachable for a while, <strong>Reveal</strong> in the top bar shows the full hidden list so the host can finish scoring that turn by ear. It intentionally ends secrecy for that question, so it's meant as a last resort.</li>
    </ul>
</x-slot:rules>
<div>
    {{--
        Teleported (see layouts/app.blade.php's #game-actions anchor) rather
        than a layout $barRight slot -- these buttons depend on $started/
        $phase, which change after the page's first render, and a layout
        slot only ever captures its content once, at that first render.
    --}}
    @if ($started)
        @teleport('#game-actions')
            <div style="display: contents;">
                {{--
                    Hidden outright for an online spectator (no seat at
                    all) -- these would just 403 on click, same reasoning
                    as Yahtzee hiding its Roll button from a spectator
                    rather than merely disabling it. Local play always
                    passes (mySeatIndex is never checked when room is
                    null).
                --}}
                @if ($room === null || $this->mySeatIndex !== null)
                    @if ($this->inPlay)
                        <button type="button" id="reveal-trigger" data-dialog-open="reveal-confirm-dialog">
                            Reveal
                        </button>

                        <x-confirm-dialog id="reveal-confirm-dialog" title="Reveal answers?"
                            message="This shows every hidden answer and ends secrecy for this question. Continue?">
                            <x-slot:confirm>
                                <button type="button" id="reveal-confirm" data-dialog-close wire:click="revealManually"
                                    class="flex-1 rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-warn text-warn">
                                    Reveal
                                </button>
                            </x-slot:confirm>
                        </x-confirm-dialog>
                    @endif
                    @unless ($phase === FamilyFeudPhase::GameEnd)
                        <button type="button" id="end-game-trigger" data-dialog-open="end-game-confirm-dialog">
                            End Game
                        </button>

                        <x-confirm-dialog id="end-game-confirm-dialog" title="End the game?"
                            message="End the game now and show final scores?">
                            <x-slot:confirm>
                                <button type="button" id="end-game-confirm" data-dialog-close wire:click="endGame"
                                    class="flex-1 rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-warn text-warn">
                                    End Game
                                </button>
                            </x-slot:confirm>
                        </x-confirm-dialog>
                    @else
                        @if ($room === null)
                            <button type="button" wire:click="newGame">New Game</button>
                        @else
                            <a href="{{ route('family-feud.room', ['code' => $room]) }}"><button type="button">Back to room</button></a>
                        @endif
                    @endunless
                @endif
            </div>
        @endteleport
    @endif

    @unless ($started)
        <div class="setup" id="setup">
            <div class="heading">Survey Says</div>

            @if ($this->hostedRooms->isNotEmpty())
                <div class="flex flex-col items-center gap-2 w-full">
                    <p class="status-line">Play in a room you host:</p>
                    <div class="flex flex-wrap justify-center gap-2">
                        @foreach ($this->hostedRooms as $hostedRoom)
                            <a href="{{ route('family-feud.room', ['code' => $hostedRoom->code]) }}" wire:key="hosted-room-{{ $hostedRoom->id }}">
                                <button type="button" id="hosted-room-{{ $hostedRoom->code }}">{{ $hostedRoom->code }}</button>
                            </a>
                        @endforeach
                    </div>
                </div>

                <div class="status-line">or play locally:</div>
            @endif

            <div class="team-inputs">
                <div class="team-input-row">
                    <label for="team0">Team 1</label>
                    <input type="text" id="team0" wire:model="teamNames.0" maxlength="24" placeholder="Team 1">
                </div>
                <div class="team-input-row">
                    <label for="team1">Team 2</label>
                    <input type="text" id="team1" wire:model="teamNames.1" maxlength="24" placeholder="Team 2">
                </div>
            </div>

            <div class="setup-row">
                <label>Rounds</label>
                <div class="stepper">
                    <button type="button" wire:click="setRounds({{ $rounds - 1 }})" @disabled($rounds <= self::MIN_ROUNDS) aria-label="Fewer rounds">&minus;</button>
                    <div class="stepper-value">{{ $rounds }}</div>
                    <button type="button" wire:click="setRounds({{ $rounds + 1 }})" @disabled($rounds >= $this->maxRounds()) aria-label="More rounds">+</button>
                </div>
            </div>
            <div class="setup-note">Each round, Team 1 and Team 2 both get a turn — Team 1 goes first.</div>

            @auth
                <div class="setup-row">
                    <div class="label">Which team is you?</div>
                    <div class="option-group">
                        <button type="button" class="option {{ $meTeamIndex === null ? 'active' : '' }}" wire:click="$set('meTeamIndex', null)">Not me</button>
                        <button type="button" class="option {{ $meTeamIndex === 0 ? 'active' : '' }}" wire:click="$set('meTeamIndex', 0)">{{ $teamNames[0] ?: 'Team 1' }}</button>
                        <button type="button" class="option {{ $meTeamIndex === 1 ? 'active' : '' }}" wire:click="$set('meTeamIndex', 1)">{{ $teamNames[1] ?: 'Team 2' }}</button>
                    </div>
                </div>
            @endauth

            <button type="button" wire:click="startGame">Start Game</button>

            @if ($message)
                <div class="message {{ $messageIsError ? 'error' : '' }}">{{ $message }}</div>
            @endif
        </div>
    @else
        <div id="family-feud-game">
            <div class="scoreboard">
                <div class="team-card {{ $this->inPlay && $this->turnTeam() === 0 ? 'controlling' : '' }}">
                    <div class="team-name">{{ $teams[0]['name'] }}</div>
                    <div class="team-score">{{ $teams[0]['score'] }}</div>
                </div>
                <div class="round-column">
                    <div class="round-indicator">{{ $this->roundIndicator }}</div>
                    <div class="pot {{ $phase === FamilyFeudPhase::GameEnd || $phase === FamilyFeudPhase::RoundSummary ? 'hidden' : '' }}">Pot: {{ $pot }}</div>
                </div>
                <div class="team-card {{ $this->inPlay && $this->turnTeam() === 1 ? 'controlling' : '' }}">
                    <div class="team-name">{{ $teams[1]['name'] }}</div>
                    <div class="team-score">{{ $teams[1]['score'] }}</div>
                </div>
            </div>

            @unless ($phase === FamilyFeudPhase::GameEnd || $phase === FamilyFeudPhase::RoundSummary)
                <div class="board-panel">
                    <div class="prompt">{{ $this->currentQuestion()['prompt'] }}</div>
                    <div class="board">
                        @for ($i = 0; $i < $this->currentQuestion()['answerCount']; $i++)
                            <div wire:key="tile-{{ $i }}" class="tile {{ isset($revealed[$i]) ? 'revealed' : '' }}">
                                <div class="tile-index">{{ $i + 1 }}</div>
                                <div class="tile-content">
                                    <div class="tile-text">{{ isset($revealed[$i]) ? strtoupper($revealed[$i]['text']) : '' }}</div>
                                    <div class="tile-points">{{ $revealed[$i]['points'] ?? '' }}</div>
                                </div>
                            </div>
                        @endfor
                    </div>
                </div>

                <div class="strikes">
                    <span class="strikes-label">Strikes</span>
                    @for ($i = 0; $i < 3; $i++)
                        <span class="strike-mark {{ $i < $strikes ? 'active' : '' }}">&times;</span>
                    @endfor
                </div>

                <div class="message {{ $messageIsError ? 'error' : '' }}">{{ $message }}</div>
            @endunless

            {{--
                Online only -- who's currently allowed to guess/steal, so
                everyone watching their own phone knows without needing to
                tap anything. Empty string outside Play/Steal or locally
                (see getTurnStatusProperty()).
            --}}
            @if ($room !== null && $this->turnStatus !== '')
                <div class="status-line">{{ $this->turnStatus }}</div>
            @endif

            @if ($this->inPlay)
                @if ($room !== null && ! $this->isMyTurn)
                    <p class="status-line">Waiting for {{ $this->turnHolderName() ?? 'the other team' }}...</p>
                @else
                    @if ($pendingGuess !== null)
                        <button type="button" wire:click="retryGuess">Retry</button>
                    @else
                        <form class="guess-form" wire:submit="submitGuess">
                            <input type="text" wire:model="guessInput" placeholder="Type the answer..." autocomplete="off"
                                wire:loading.attr="disabled" wire:target="submitGuess">
                            <button type="submit" wire:loading.attr="disabled" wire:target="submitGuess">
                                <span wire:loading.remove wire:target="submitGuess">Submit</span>
                                <span wire:loading wire:target="submitGuess">Checking...</span>
                            </button>
                        </form>
                    @endif

                    <button type="button" id="turn-options-toggle" class="link-toggle" wire:click="toggleTurnOptions">
                        Options {!! $turnOptionsOpen ? '&#9652;' : '&#9662;' !!}
                    </button>

                    @if ($turnOptionsOpen)
                        <div class="controls">
                            <button type="button" id="skip-question-trigger" data-dialog-open="skip-question-confirm-dialog">
                                Skip Question
                            </button>

                            <x-confirm-dialog id="skip-question-confirm-dialog" title="Skip this question?"
                                message="Try a different one? Any points or strikes so far on it will be lost.">
                                <x-slot:confirm>
                                    <button type="button" id="skip-question-confirm" data-dialog-close wire:click="skipQuestion"
                                        class="flex-1 rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-warn text-warn">
                                        Skip Question
                                    </button>
                                </x-slot:confirm>
                            </x-confirm-dialog>
                            @if ($phase === FamilyFeudPhase::Play)
                                <button type="button" wire:click="swapControl">Swap Team</button>
                            @endif
                        </div>
                    @endif
                @endif
            @endif

            @if ($phase === FamilyFeudPhase::Manual && ($room === null || $this->mySeatIndex !== null))
                <div class="panel">
                    <div class="panel-title">Finish this round by eye</div>
                    <p>The hidden answers are now shown above. Talk it through, then award the pot to whichever team you agree got it.</p>
                    <div class="controls">
                        <button type="button" wire:click="awardManual(0)">Award pot to {{ $teams[0]['name'] }}</button>
                        <button type="button" wire:click="awardManual(1)">Award pot to {{ $teams[1]['name'] }}</button>
                    </div>
                </div>
            @endif

            @if ($phase === FamilyFeudPhase::RoundEnd && ($room === null || $this->mySeatIndex !== null))
                <div class="panel">
                    <div class="panel-title">{{ $roundEndTitle }}</div>
                    <div class="controls">
                        <button type="button" wire:click="nextRound">
                            {{ $currentQuestionIndex % 2 === 0 ? 'Next Question' : 'See Round Summary' }}
                        </button>
                    </div>
                </div>
            @endif

            @if ($phase === FamilyFeudPhase::RoundSummary)
                @php $justFinished = intdiv($currentQuestionIndex, 2); @endphp
                <div class="panel">
                    <div class="panel-title">Round {{ $justFinished + 1 }} Complete</div>
                    <div class="round-summary-scores">
                        <div class="team-card">
                            <div class="team-name">{{ $teams[0]['name'] }}</div>
                            <div class="team-score">{{ $roundHistory[$justFinished][0] }}</div>
                        </div>
                        <div class="team-card">
                            <div class="team-name">{{ $teams[1]['name'] }}</div>
                            <div class="team-score">{{ $roundHistory[$justFinished][1] }}</div>
                        </div>
                    </div>
                    @if ($room === null || $this->mySeatIndex !== null)
                        <div class="controls">
                            <button type="button" wire:click="nextRoundSummary">
                                {{ $currentQuestionIndex + 1 >= count($questions) ? 'See Final Score' : 'Next Round' }}
                            </button>
                        </div>
                    @endif
                </div>
            @endif

            @if ($phase === FamilyFeudPhase::GameEnd)
                <div class="panel">
                    <div class="panel-title">Final Score</div>
                    <div class="panel-title">{{ $this->winnerText() }}</div>
                    <table class="round-table">
                        <thead>
                            <tr>
                                <th>Round</th>
                                <th>{{ $teams[0]['name'] }}</th>
                                <th>{{ $teams[1]['name'] }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($roundHistory as $i => $result)
                                <tr wire:key="round-{{ $i }}">
                                    <td>Round {{ $i + 1 }}</td>
                                    <td>{{ $result[0] }}</td>
                                    <td>{{ $result[1] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Total</td>
                                <td>{{ $teams[0]['score'] }}</td>
                                <td>{{ $teams[1]['score'] }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    @endunless
</div>

