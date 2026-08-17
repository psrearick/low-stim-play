<?php

use App\Enums\AppleMatchPhase;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Events\RoomGameStateUpdated;
use App\Models\AppleMatchCard;
use App\Models\AppleMatchPrompt;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    public const int HAND_SIZE = 7;

    /**
     * The online room this play-through belongs to -- mirrors every other
     * game's own `$room`. Best Match has no pass-and-play mode (see
     * docs/specs/apple-match.md section 1), so unlike Boggle/Yahtzee/
     * Family Feud/Farkle this is the *only* way this page ever has
     * anything to show; a bare visit (see the `setup` panel below) only
     * ever offers a "play in a room you host" list, never a local setup
     * form.
     */
    #[Url]
    public ?string $room = null;

    public bool $started = false;

    public ?int $gameSessionId = null;

    public ?int $deckId = null;

    public int $targetScore = 4;

    /**
     * @var list<array{seatIndex: int, name: string}>
     */
    public array $seats = [];

    /**
     * seatIndex => green cards won.
     *
     * @var array<int, int>
     */
    public array $scores = [];

    /**
     * seatIndex => rounds judged so far, for stats only.
     *
     * @var array<int, int>
     */
    public array $judgedCounts = [];

    public int $judgeSeatIndex = 0;

    public int $round = 1;

    public AppleMatchPhase $phase = AppleMatchPhase::Submitting;

    /**
     * @var list<int>
     */
    public array $usedPromptIds = [];

    public int $currentPromptId = 0;

    public string $currentPromptText = '';

    /**
     * Which seats have submitted a card this round -- never which card, see
     * the attribution note on submitCard() below.
     *
     * @var list<int>
     */
    public array $submittedSeats = [];

    /**
     * This round's submitted cards, unattributed and shuffled -- safe to
     * broadcast to every seat once populated. See submitCard()'s own note:
     * the seat that played each one is never included here or anywhere
     * else in $state.
     *
     * @var list<array{cardId: int, text: string}>
     */
    public array $anonymizedSubmissions = [];

    public ?string $winningText = null;

    public ?string $winningSeatName = null;

    /**
     * This connection's seat, or null for an unseated spectator.
     */
    public ?int $mySeatIndex = null;

    /**
     * This seat's own private hand -- {id, text} pairs, loaded fresh from
     * RoomSeat::$hand (never from $state; see class-level notes below).
     *
     * @var list<array{id: int, text: string}>
     */
    public array $myHand = [];

    public function mount(): void
    {
        if ($this->room === null) {
            return;
        }

        $roomModel = $this->resolveRoom();
        $session = $roomModel->gameSession;

        // A stale tab/bookmark, or a hand-edited ?room= for a room that's
        // actively playing something else, can land here with no session
        // at all or one belonging to a different game -- hydrateFromState()
        // would otherwise throw on a null/foreign state shape. Treat it the
        // same as "no active game here" and bounce to the room hub instead.
        if ($session === null || $session->type !== GameType::AppleMatch) {
            $this->redirect(route('room.show', ['code' => $roomModel->code]));

            return;
        }

        $this->gameSessionId = $session->id;
        $this->hydrateFromState($session->state);

        $seat = RoomSeat::where('room_id', $roomModel->id)->where('user_id', Auth::id())->first();
        $this->mySeatIndex = $seat?->seat_index;
        $this->myHand = $this->loadHand($seat);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function hydrateFromState(array $state): void
    {
        $this->deckId = $state['deckId'];
        $this->targetScore = $state['targetScore'];
        $this->seats = $state['seats'];
        $this->scores = $state['scores'];
        $this->judgedCounts = $state['judgedCounts'];
        $this->judgeSeatIndex = $state['judgeSeatIndex'];
        $this->round = $state['round'];
        $this->phase = AppleMatchPhase::from($state['phase']);
        $this->usedPromptIds = $state['usedPromptIds'];
        $this->currentPromptId = $state['currentPromptId'];
        $this->currentPromptText = $state['currentPromptText'];
        $this->submittedSeats = $state['submittedSeats'];
        $this->anonymizedSubmissions = $state['anonymizedSubmissions'];
        $this->winningText = $state['winningText'];
        $this->winningSeatName = $state['winningSeatName'];
        $this->started = true;
    }

    /**
     * @return list<array{id: int, text: string}>
     */
    private function loadHand(?RoomSeat $seat): array
    {
        if ($seat === null || $seat->hand === null || $seat->hand === []) {
            return [];
        }

        return AppleMatchCard::whereIn('id', $seat->hand)->get()
            ->map(fn (AppleMatchCard $card) => ['id' => $card->id, 'text' => $card->text])
            ->sortBy('text')
            ->values()
            ->all();
    }

    private function resolveRoom(): Room
    {
        return Room::where('code', $this->room)->firstOrFail();
    }

    /**
     * Persists + broadcasts the shared round state. Deliberately never
     * includes hand contents or round attribution -- see submitCard()'s
     * own note. Wrapped in a row-locked transaction as cheap insurance
     * against a double-tap/retry racing itself, same as every other
     * game's own syncOnlineState().
     */
    private function syncOnlineState(): void
    {
        $state = [
            'deckId' => $this->deckId,
            'targetScore' => $this->targetScore,
            'seats' => $this->seats,
            'scores' => $this->scores,
            'judgedCounts' => $this->judgedCounts,
            'judgeSeatIndex' => $this->judgeSeatIndex,
            'round' => $this->round,
            'phase' => $this->phase->value,
            'usedPromptIds' => $this->usedPromptIds,
            'currentPromptId' => $this->currentPromptId,
            'currentPromptText' => $this->currentPromptText,
            'submittedSeats' => $this->submittedSeats,
            'anonymizedSubmissions' => $this->anonymizedSubmissions,
            'winningText' => $this->winningText,
            'winningSeatName' => $this->winningSeatName,
        ];

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

        // A hand can change from another seat's own action (nextRound()'s
        // replenish, run by whichever seated player happened to click it)
        // without this connection ever calling submitCard() itself, so it
        // has to be reloaded from the database here rather than assumed
        // stale-safe.
        if ($this->mySeatIndex !== null) {
            $seat = RoomSeat::where('room_id', $this->resolveRoom()->id)
                ->where('seat_index', $this->mySeatIndex)
                ->first();
            $this->myHand = $this->loadHand($seat);
        }
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
     * A non-judge seat may submit once per round, only while Submitting.
     * Private/uncached deliberately -- submitCard() below both reads this
     * as its own guard and mutates $submittedSeats/$phase in the same
     * request, which is exactly the pattern that caused Farkle's and
     * Family Feud's own stale-computed-property bugs (see
     * docs/DEVLOG.md's Family Feud section, 2026-08-05 entry, and
     * docs/specs/apple-match.md section 3). The public getter below is
     * only ever read by Blade, so its first cache population per request
     * always happens after any mutation.
     */
    private function canSubmitValue(): bool
    {
        return $this->mySeatIndex !== null
            && $this->phase === AppleMatchPhase::Submitting
            && $this->mySeatIndex !== $this->judgeSeatIndex
            && ! in_array($this->mySeatIndex, $this->submittedSeats, true);
    }

    public function getCanSubmitProperty(): bool
    {
        return $this->canSubmitValue();
    }

    private function isJudgeValue(): bool
    {
        return $this->mySeatIndex !== null && $this->mySeatIndex === $this->judgeSeatIndex;
    }

    public function getIsJudgeProperty(): bool
    {
        return $this->isJudgeValue();
    }

    /**
     * Removes the card from this seat's own hand and records who played it
     * -- but that attribution is deliberately never assigned to any public
     * property on this component, and never included in syncOnlineState()'s
     * $state (the payload every seat's browser receives over the
     * websocket). It's held only in cache, keyed to this round, and read
     * back exactly once, fresh, inside pickWinner() below -- the same
     * "never leak secret data into what gets broadcast" rule Family Feud's
     * JudgeService/question-bank hazard established, applied to a stronger
     * requirement here: even a public property that Blade never renders
     * would still leak, since the raw broadcast payload is inspectable in
     * any connected client's own browser devtools.
     */
    public function submitCard(int $cardId): void
    {
        abort_unless($this->canSubmitValue(), 403);
        abort_unless(collect($this->myHand)->pluck('id')->contains($cardId), 403);

        $seat = RoomSeat::where('room_id', $this->resolveRoom()->id)
            ->where('seat_index', $this->mySeatIndex)
            ->first();

        $seat->update(['hand' => array_values(array_diff($seat->hand, [$cardId]))]);
        $this->myHand = array_values(array_filter($this->myHand, fn (array $card) => $card['id'] !== $cardId));

        $this->submittedSeats[] = $this->mySeatIndex;

        $attribution = $this->roundAttribution();
        $attribution[$cardId] = $this->mySeatIndex;
        $this->putRoundAttribution($attribution);

        $nonJudgeSeatCount = count($this->seats) - 1;

        if (count($this->submittedSeats) >= $nonJudgeSeatCount) {
            $this->revealSubmissions($attribution);
        }

        $this->syncOnlineState();
    }

    /**
     * Builds the anonymized, shuffled reveal list every seat sees once
     * everyone's submitted -- card text only, no seat mapping.
     *
     * @param  array<int, int>  $attribution
     */
    private function revealSubmissions(array $attribution): void
    {
        $cards = AppleMatchCard::whereIn('id', array_keys($attribution))->get()->keyBy('id');

        $this->anonymizedSubmissions = collect(array_keys($attribution))
            ->map(fn (int $cardId) => ['cardId' => $cardId, 'text' => $cards[$cardId]->text])
            ->shuffle()
            ->values()
            ->all();

        $this->phase = AppleMatchPhase::Judging;
    }

    /**
     * Judge-only. Resolves the real winning seat from the private
     * attribution cache -- read fresh here, never assigned to a public
     * property, and cleared immediately after use.
     */
    public function pickWinner(int $cardId): void
    {
        abort_unless($this->isJudgeValue(), 403);
        abort_unless($this->phase === AppleMatchPhase::Judging, 403);

        $attribution = $this->roundAttribution();
        abort_unless(array_key_exists($cardId, $attribution), 403);

        $winningSeatIndex = $attribution[$cardId];
        $winningEntry = collect($this->anonymizedSubmissions)->firstWhere('cardId', $cardId);

        $this->scores[$winningSeatIndex] = ($this->scores[$winningSeatIndex] ?? 0) + 1;
        $this->winningText = $winningEntry['text'];
        $this->winningSeatName = $this->seatName($winningSeatIndex);

        $this->clearRoundAttribution();

        if ($this->scores[$winningSeatIndex] >= $this->targetScore) {
            $this->phase = AppleMatchPhase::GameEnd;
            $this->finishGame($winningSeatIndex);
        } else {
            $this->phase = AppleMatchPhase::RoundEnd;
        }

        $this->syncOnlineState();
    }

    /**
     * Any seated player may advance -- a shared "we're ready to continue"
     * decision, not a turn, same as Family Feud's own nextRound()/
     * nextRoundSummary(). Rotates the judge, replenishes every non-judge
     * seat's hand back to HAND_SIZE, and deals the next prompt.
     */
    public function nextRound(): void
    {
        abort_unless($this->mySeatIndex !== null, 403);
        abort_unless($this->phase === AppleMatchPhase::RoundEnd, 403);

        $outgoingJudgeSeatIndex = $this->judgeSeatIndex;
        $seats = RoomSeat::where('room_id', $this->resolveRoom()->id)->get()->keyBy('seat_index');
        $dealtCardIds = $seats->flatMap(fn (RoomSeat $seat) => $seat->hand ?? [])->all();

        foreach ($seats as $seatIndex => $seat) {
            if ($seatIndex === $outgoingJudgeSeatIndex) {
                continue;
            }

            $needed = self::HAND_SIZE - count($seat->hand ?? []);

            if ($needed <= 0) {
                continue;
            }

            $newCardIds = $this->drawCards($needed, $dealtCardIds);
            $seat->update(['hand' => array_merge($seat->hand ?? [], $newCardIds)]);
            $dealtCardIds = array_merge($dealtCardIds, $newCardIds);
        }

        $this->judgedCounts[$outgoingJudgeSeatIndex] = ($this->judgedCounts[$outgoingJudgeSeatIndex] ?? 0) + 1;
        $this->judgeSeatIndex = ($outgoingJudgeSeatIndex + 1) % count($this->seats);
        $this->round++;

        $nextPromptId = AppleMatchPrompt::where('deck_id', $this->deckId)
            ->whereNotIn('id', $this->usedPromptIds)
            ->inRandomOrder()
            ->value('id')
            ?? AppleMatchPrompt::where('deck_id', $this->deckId)->inRandomOrder()->value('id');

        $this->usedPromptIds[] = $nextPromptId;
        $this->currentPromptId = $nextPromptId;
        $this->currentPromptText = AppleMatchPrompt::find($nextPromptId)->text;
        $this->submittedSeats = [];
        $this->anonymizedSubmissions = [];
        $this->winningText = null;
        $this->winningSeatName = null;
        $this->phase = AppleMatchPhase::Submitting;

        $this->syncOnlineState();

        if ($this->mySeatIndex !== null) {
            $this->myHand = $this->loadHand($seats[$this->mySeatIndex] ?? null);
        }
    }

    /**
     * Draws $count card ids not already dealt this round, falling back to
     * allowing reuse (rather than leaving a hand short) if the deck's own
     * card set is smaller than what a long game needs -- see
     * docs/specs/apple-match.md section 2.
     *
     * @param  list<int>  $dealtCardIds
     * @return list<int>
     */
    private function drawCards(int $count, array $dealtCardIds): array
    {
        $fresh = AppleMatchCard::where('deck_id', $this->deckId)
            ->whereNotIn('id', $dealtCardIds)
            ->inRandomOrder()
            ->limit($count)
            ->pluck('id')
            ->all();

        if (count($fresh) < $count) {
            $fresh = array_merge($fresh, AppleMatchCard::where('deck_id', $this->deckId)
                ->inRandomOrder()
                ->limit($count - count($fresh))
                ->pluck('id')
                ->all());
        }

        return $fresh;
    }

    private function finishGame(int $winningSeatIndex): void
    {
        $session = GameSession::findOrFail($this->gameSessionId);
        $session->update(['ended_at' => now()]);

        // See docs/DEVLOG.md's Farkle "Back to room" fix -- without this,
        // Room.status/game_session_id stay exactly as onRoomStarting() left
        // them, and pages::apple-match.room's own redirectIfAlreadyStarted()
        // bounces "Back to room" straight back into this now-finished game.
        $this->resolveRoom()->update(['status' => RoomStatus::Finished]);

        $seats = RoomSeat::where('room_id', $this->resolveRoom()->id)->get();

        foreach ($seats as $seat) {
            $score = $this->scores[$seat->seat_index] ?? 0;

            GameParticipant::create([
                'game_session_id' => $session->id,
                'user_id' => $seat->user_id,
                'guest_name' => null,
                'score' => $score,
                'result' => $seat->seat_index === $winningSeatIndex ? GameResult::Win : GameResult::Loss,
                'stats' => [
                    'green_cards_won' => $score,
                    'rounds_judged' => $this->judgedCounts[$seat->seat_index] ?? 0,
                ],
            ]);
        }
    }

    private function seatName(int $seatIndex): ?string
    {
        return collect($this->seats)->firstWhere('seatIndex', $seatIndex)['name'] ?? null;
    }

    /**
     * @return array<int, int>
     */
    private function roundAttribution(): array
    {
        return Cache::get($this->attributionCacheKey(), []);
    }

    /**
     * @param  array<int, int>  $attribution
     */
    private function putRoundAttribution(array $attribution): void
    {
        // Same 3-hour TTL as <livewire:game-lobby>'s own ready-state cache
        // -- generous insurance against an abandoned round, not a value
        // with any other significance.
        Cache::put($this->attributionCacheKey(), $attribution, now()->addHours(3));
    }

    private function clearRoundAttribution(): void
    {
        Cache::forget($this->attributionCacheKey());
    }

    private function attributionCacheKey(): string
    {
        return "apple-match:{$this->gameSessionId}:round:{$this->round}:attribution";
    }

    public function getIsSpectatorProperty(): bool
    {
        return $this->room !== null && $this->mySeatIndex === null;
    }

    public function getWinnerNameProperty(): ?string
    {
        $topScore = max($this->scores);
        $winningSeatIndex = array_search($topScore, $this->scores, true);

        return $this->seatName($winningSeatIndex);
    }
};
?>

<x-slot:title>Best Match — Low Stim Play</x-slot>
<x-slot:description>A rotating judge reveals a prompt card and everyone else secretly submits their best-matching answer card, in a calm, low-contrast online room.</x-slot:description>
<x-slot:bodyClass>apple-match-play</x-slot:bodyClass>
<x-slot:fullscreenToggle>1</x-slot:fullscreenToggle>
<x-slot:rulesTitle>Best Match Rules</x-slot:rulesTitle>
<x-slot:rules>
    <h3>How to Use This Page</h3>
    <ul>
        <li>Every player joins from their own device -- there's no shared/host screen, since everyone's hand is private.</li>
        <li>On your turn to submit, tap a card from your own hand that best matches the prompt shown. The judge that round can't submit.</li>
        <li>Once everyone's submitted, the judge sees every submitted card (no names attached) and taps their favorite. Whoever played it wins that round's prompt card.</li>
        <li>The judge role rotates by one seat every round.</li>
    </ul>

    <h3>Full Rules</h3>
    <ul>
        <li>Every non-judge seat submits exactly one card each round; the judge doesn't submit and their hand stays the same until their next non-judge round.</li>
        <li>Submitted cards are shown to everyone once every non-judge seat has played, shuffled with no indication of who played which one.</li>
        <li>The judge alone picks a winner; that player wins the round's prompt card and everyone who submitted draws back up to a full hand.</li>
        <li>First to reach the room's target number of prompt cards wins the game.</li>
    </ul>
</x-slot:rules>
<div>
    @if ($started)
        @teleport('#game-actions')
            <div style="display: contents;">
                @if ($phase === AppleMatchPhase::GameEnd)
                    <a href="{{ route('room.show', ['code' => $room]) }}"><button type="button">Back to room</button></a>
                @endif
            </div>
        @endteleport
    @endif

    @if ($room === null)
        <div class="setup" id="setup">
            <div class="heading">Best Match</div>

            @if ($this->hostedRooms->isNotEmpty())
                <div class="flex flex-col items-center gap-2 w-full">
                    <p class="status-line">Play in a room you host:</p>
                    <div class="flex flex-wrap justify-center gap-2">
                        @foreach ($this->hostedRooms as $hostedRoom)
                            <a href="{{ route('apple-match.room', ['code' => $hostedRoom->code]) }}" wire:key="hosted-room-{{ $hostedRoom->id }}">
                                <button type="button" id="hosted-room-{{ $hostedRoom->code }}">{{ $hostedRoom->code }}</button>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            <p class="status-line">Best Match is online-only -- open <strong>Rooms</strong> in the top bar to create or join a room, then start Best Match from there.</p>
        </div>
    @else
        <div id="apple-match-game" class="flex flex-col items-center w-full" style="gap: inherit;">
            <div class="status-line">
                Round {{ $round }} &middot; {{ $this->seatName($judgeSeatIndex) }} is judging &middot; First to {{ $targetScore }}
            </div>

            @unless ($phase === AppleMatchPhase::GameEnd)
                <div class="panel">
                    <div class="panel-title">{{ $currentPromptText }}</div>
                </div>

                <div class="flex flex-wrap justify-center gap-3 w-full">
                    @foreach ($seats as $seat)
                        <div wire:key="score-{{ $seat['seatIndex'] }}" class="flex flex-col items-center gap-1 bg-surface border-2 border-surface-border rounded-lg px-4 py-2">
                            <span class="text-sm text-text/80">{{ $seat['name'] }}{{ $seat['seatIndex'] === $judgeSeatIndex ? ' (judge)' : '' }}</span>
                            <span class="text-lg font-bold text-text">{{ $scores[$seat['seatIndex']] ?? 0 }}</span>
                        </div>
                    @endforeach
                </div>
            @endunless

            @if ($phase === AppleMatchPhase::Submitting)
                @if ($this->isJudge)
                    <p class="status-line">Waiting for everyone to submit... ({{ count($submittedSeats) }}/{{ count($seats) - 1 }})</p>
                @elseif ($this->canSubmit)
                    <p class="status-line">Pick your best match:</p>
                    <div class="controls">
                        @foreach ($myHand as $card)
                            <button type="button" wire:key="hand-{{ $card['id'] }}" wire:click="submitCard({{ $card['id'] }})">
                                {{ $card['text'] }}
                            </button>
                        @endforeach
                    </div>
                @elseif ($this->isSpectator)
                    <p class="status-line">Watching -- {{ count($submittedSeats) }}/{{ count($seats) - 1 }} submitted.</p>
                @else
                    <p class="status-line">Waiting for the rest of the table...</p>
                @endif
            @endif

            @if ($phase === AppleMatchPhase::Judging)
                @if ($this->isJudge)
                    <p class="status-line">Pick your favorite:</p>
                    <div class="controls">
                        @foreach ($anonymizedSubmissions as $submission)
                            <button type="button" wire:key="submission-{{ $submission['cardId'] }}" wire:click="pickWinner({{ $submission['cardId'] }})">
                                {{ $submission['text'] }}
                            </button>
                        @endforeach
                    </div>
                @else
                    <p class="status-line">Waiting for {{ $this->seatName($judgeSeatIndex) }} to pick a favorite...</p>
                    <div class="flex flex-col items-stretch gap-2 w-full max-w-sm">
                        @foreach ($anonymizedSubmissions as $submission)
                            <div wire:key="submission-view-{{ $submission['cardId'] }}" class="bg-surface border-2 border-surface-border rounded-lg px-4 py-2 text-center text-text">{{ $submission['text'] }}</div>
                        @endforeach
                    </div>
                @endif
            @endif

            @if ($phase === AppleMatchPhase::RoundEnd)
                <div class="panel">
                    <div class="panel-title">{{ $winningSeatName }} wins the round with "{{ $winningText }}"!</div>
                    @if ($mySeatIndex !== null)
                        <div class="controls">
                            <button type="button" wire:click="nextRound">Next Round</button>
                        </div>
                    @endif
                </div>
            @endif

            @if ($phase === AppleMatchPhase::GameEnd)
                <div class="panel">
                    <div class="panel-title">Final Score</div>
                    <div class="panel-title">{{ $this->winnerName }} wins!</div>
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
