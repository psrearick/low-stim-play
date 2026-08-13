<?php

use App\Enums\ClearTheDeckPhase;
use App\Enums\GameResult;
use App\Enums\RoomStatus;
use App\Events\RoomGameStateUpdated;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    /**
     * Online room this play-through belongs to -- Clear the Deck has no
     * pass-and-play mode (see docs/specs/clear-the-deck.md section 1), same
     * reasoning as pages::apple-match.play's own $room.
     */
    #[Url]
    public ?string $room = null;

    public bool $started = false;

    public ?int $gameSessionId = null;

    public int $roundTarget = 1;

    public int $currentRound = 1;

    /**
     * @var list<array{seatIndex: int, name: string}>
     */
    public array $seats = [];

    /**
     * Cumulative points across every round played so far -- lower is
     * better, unlike every other game's own score.
     *
     * @var array<int, int>
     */
    public array $scores = [];

    /**
     * seatIndex => rounds gone out first in, for stats only.
     *
     * @var array<int, int>
     */
    public array $roundsWonCounts = [];

    public int $dealerSeatIndex = 0;

    public int $turnSeatIndex = 0;

    /**
     * Every card currently in the shared center pile, in play order (last
     * entry = current top, the value the next play must match or beat
     * under). Picking it up takes the whole thing, not just the top card,
     * so the full list -- not just the top value -- has to be kept.
     *
     * @var list<int>
     */
    public array $centerStack = [];

    public ClearTheDeckPhase $phase = ClearTheDeckPhase::Playing;

    public ?string $lastMessage = null;

    /**
     * RoundEnd only: this round's own point total per seat, cleared by
     * nextRound().
     *
     * @var array<int, int>
     */
    public array $roundScores = [];

    public ?int $wentOutSeatIndex = null;

    public ?string $winnerName = null;

    /**
     * Public per-seat table info -- hand *count* (never contents), face-up
     * card *values* (public even for other seats, see appendix), and
     * face-down *count* (never contents/values -- see class-level notes on
     * playCards() below for why).
     *
     * @var array<int, array{handCount: int, tableFaceUp: list<int>, tableFaceDownCount: int}>
     */
    public array $seatTables = [];

    /**
     * This connection's seat, or null for an unseated spectator.
     */
    public ?int $mySeatIndex = null;

    /**
     * This seat's own private hand -- loaded fresh from RoomSeat::$hand
     * (never from $state; see class-level notes below).
     *
     * @var list<int>
     */
    public array $myHand = [];

    /**
     * @var list<int>
     */
    public array $selectedHandIndices = [];

    /**
     * @var list<int>
     */
    public array $selectedFaceUpIndices = [];

    public int $selectedFaceDownCount = 0;

    public ?string $playError = null;

    public function mount(): void
    {
        if ($this->room === null) {
            return;
        }

        $roomModel = $this->resolveRoom();
        $session = $roomModel->gameSession;

        $this->gameSessionId = $session->id;
        $this->hydrateFromState($session->state);

        $seat = RoomSeat::where('room_id', $roomModel->id)->where('user_id', Auth::id())->first();
        $this->mySeatIndex = $seat?->seat_index;
        $this->myHand = $seat->hand ?? [];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function hydrateFromState(array $state): void
    {
        $this->roundTarget = $state['roundTarget'];
        $this->currentRound = $state['currentRound'];
        $this->seats = $state['seats'];
        $this->scores = $state['scores'];
        $this->roundsWonCounts = $state['roundsWonCounts'];
        $this->dealerSeatIndex = $state['dealerSeatIndex'];
        $this->turnSeatIndex = $state['turnSeatIndex'];
        $this->centerStack = $state['centerStack'];
        $this->phase = ClearTheDeckPhase::from($state['phase']);
        $this->lastMessage = $state['lastMessage'];
        $this->roundScores = $state['roundScores'];
        $this->wentOutSeatIndex = $state['wentOutSeatIndex'];
        $this->winnerName = $state['winnerName'];
        $this->seatTables = $state['seatTables'];
        $this->started = true;
    }

    private function resolveRoom(): Room
    {
        return Room::where('code', $this->room)->firstOrFail();
    }

    /**
     * Persists + broadcasts the shared game state. Deliberately never
     * includes hand contents or face-down identities -- see playCards()'s
     * own note. Refreshes $seatTables from the database first so every
     * seat's public hand-count/table info is always current, without every
     * call site needing to remember to do it separately.
     */
    private function syncOnlineState(): void
    {
        $this->refreshSeatTables();

        $state = [
            'roundTarget' => $this->roundTarget,
            'currentRound' => $this->currentRound,
            'seats' => $this->seats,
            'scores' => $this->scores,
            'roundsWonCounts' => $this->roundsWonCounts,
            'dealerSeatIndex' => $this->dealerSeatIndex,
            'turnSeatIndex' => $this->turnSeatIndex,
            'centerStack' => $this->centerStack,
            'phase' => $this->phase->value,
            'lastMessage' => $this->lastMessage,
            'roundScores' => $this->roundScores,
            'wentOutSeatIndex' => $this->wentOutSeatIndex,
            'winnerName' => $this->winnerName,
            'seatTables' => $this->seatTables,
        ];

        DB::transaction(function () use ($state) {
            GameSession::where('id', $this->gameSessionId)->lockForUpdate()->first();
            GameSession::find($this->gameSessionId)->update(['state' => $state]);
        });

        RoomGameStateUpdated::dispatch($this->resolveRoom(), $state);
    }

    private function refreshSeatTables(): void
    {
        $roomSeats = RoomSeat::where('room_id', $this->resolveRoom()->id)->get()->keyBy('seat_index');

        $this->seatTables = collect($this->seats)->mapWithKeys(function (array $seat) use ($roomSeats) {
            $roomSeat = $roomSeats[$seat['seatIndex']] ?? null;

            return [$seat['seatIndex'] => [
                'handCount' => count($roomSeat->hand ?? []),
                'tableFaceUp' => $roomSeat->table_face_up ?? [],
                'tableFaceDownCount' => count($roomSeat->table_face_down ?? []),
            ]];
        })->all();
    }

    /**
     * @param  array{state: array<string, mixed>}  $event
     */
    public function onStateUpdated(array $event): void
    {
        $this->hydrateFromState($event['state']);
        $this->resetSelection();

        if ($this->mySeatIndex !== null) {
            $seat = RoomSeat::where('room_id', $this->resolveRoom()->id)
                ->where('seat_index', $this->mySeatIndex)
                ->first();
            $this->myHand = $seat->hand ?? [];
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

    private function isMyTurnValue(): bool
    {
        return $this->mySeatIndex !== null
            && $this->phase === ClearTheDeckPhase::Playing
            && $this->mySeatIndex === $this->turnSeatIndex;
    }

    public function getIsMyTurnProperty(): bool
    {
        return $this->isMyTurnValue();
    }

    public function getIsSpectatorProperty(): bool
    {
        return $this->room !== null && $this->mySeatIndex === null;
    }

    /**
     * @return list<int>
     */
    public function getMyTableFaceUpProperty(): array
    {
        return $this->seatTables[$this->mySeatIndex]['tableFaceUp'] ?? [];
    }

    public function getMyUncoveredFaceDownCountProperty(): int
    {
        if ($this->mySeatIndex === null) {
            return 0;
        }

        $tableFaceDownCount = $this->seatTables[$this->mySeatIndex]['tableFaceDownCount'] ?? 0;

        return max(0, $tableFaceDownCount - count($this->myTableFaceUp));
    }

    public function toggleHandCard(int $index): void
    {
        $this->toggleSelection('selectedHandIndices', $index);
    }

    public function toggleFaceUpCard(int $index): void
    {
        $this->toggleSelection('selectedFaceUpIndices', $index);
    }

    /**
     * @param  'selectedHandIndices'|'selectedFaceUpIndices'  $property
     */
    private function toggleSelection(string $property, int $index): void
    {
        $this->$property = in_array($index, $this->$property, true)
            ? array_values(array_diff($this->$property, [$index]))
            : [...$this->$property, $index];
    }

    public function incrementFaceDownSelection(): void
    {
        $this->selectedFaceDownCount = min($this->myUncoveredFaceDownCount, $this->selectedFaceDownCount + 1);
    }

    public function decrementFaceDownSelection(): void
    {
        $this->selectedFaceDownCount = max(0, $this->selectedFaceDownCount - 1);
    }

    private function resetSelection(): void
    {
        $this->selectedHandIndices = [];
        $this->selectedFaceUpIndices = [];
        $this->selectedFaceDownCount = 0;
        $this->playError = null;
    }

    /**
     * Attempts the currently-selected play. A face-down selection is a
     * count, not a specific card, and is validated atomically along with
     * everything else -- if the combined values (including whatever the
     * face-down cards turn out to be) don't all match, the whole attempt is
     * rejected with no state change and nothing about the face-down cards'
     * values ever reaching this response. table_face_down is read fresh
     * from the database right here and never assigned to any public
     * property beforehand -- the same "resolve secret state fresh from the
     * DB inside the one action that needs it" rule Best Match's round
     * attribution established (docs/DEVLOG.md's Best Match section), for
     * this app's first "secret from its own owner" state. See
     * docs/specs/clear-the-deck.md section 3/4.
     */
    public function playCards(): void
    {
        abort_unless($this->isMyTurnValue(), 403);

        $seat = RoomSeat::where('room_id', $this->resolveRoom()->id)
            ->where('seat_index', $this->mySeatIndex)
            ->first();

        $hand = $seat->hand ?? [];
        $faceUp = $seat->table_face_up ?? [];
        $faceDown = $seat->table_face_down ?? [];

        $handIndices = array_values(array_unique($this->selectedHandIndices));
        $faceUpIndices = array_values(array_unique($this->selectedFaceUpIndices));
        $faceDownCount = $this->selectedFaceDownCount;

        $uncoveredFaceDown = max(0, count($faceDown) - count($faceUp));

        $indicesValid = collect($handIndices)->every(fn (int $i) => array_key_exists($i, $hand))
            && collect($faceUpIndices)->every(fn (int $i) => array_key_exists($i, $faceUp))
            && $faceDownCount >= 0 && $faceDownCount <= $uncoveredFaceDown;

        if (! $indicesValid) {
            $this->playError = 'That selection is no longer valid.';

            return;
        }

        $handValues = collect($handIndices)->map(fn (int $i) => $hand[$i])->all();
        $faceUpValues = collect($faceUpIndices)->map(fn (int $i) => $faceUp[$i])->all();

        // Face-down cards are an unordered bag -- any $faceDownCount of them
        // is equivalent to "the currently uncovered ones," since none are
        // individually distinguishable before being played. See
        // docs/specs/clear-the-deck.md section 4.
        $faceDownValues = array_slice($faceDown, 0, $faceDownCount);

        $playedValues = [...$handValues, ...$faceUpValues, ...$faceDownValues];

        if ($playedValues === []) {
            $this->playError = 'Select at least one card to play.';

            return;
        }

        $value = $playedValues[0];

        if (! collect($playedValues)->every(fn (int $v) => $v === $value)) {
            $this->resetSelection();
            $this->playError = "That combination doesn't share the same value.";

            return;
        }

        $seat->hand = array_values(array_diff_key($hand, array_flip($handIndices)));
        $seat->table_face_up = array_values(array_diff_key($faceUp, array_flip($faceUpIndices)));
        $seat->table_face_down = array_slice($faceDown, $faceDownCount);
        $seat->save();

        $this->resetSelection();
        $this->resolvePlay($seat, $value, $playedValues);
    }

    /**
     * @param  list<int>  $playedValues
     */
    private function resolvePlay(RoomSeat $seat, int $value, array $playedValues): void
    {
        $seatIndex = $seat->seat_index;
        $name = $this->seatName($seatIndex);
        $label = self::cardLabel($value);
        $count = count($playedValues);

        if ($value === 10) {
            $this->centerStack = [];
            $this->lastMessage = "{$name} played the Wild Ten and cleared the deck!";
            $this->finishTurnWithExtraTurn();

            return;
        }

        $runLength = $this->trailingRunLength($value) + $count;

        if ($runLength >= 4) {
            $this->centerStack = [];
            $this->lastMessage = "{$name} cleared the deck with four {$label}s!";
            $this->finishTurnWithExtraTurn();

            return;
        }

        $topValue = $this->centerStack === [] ? null : end($this->centerStack);

        if ($topValue !== null && $value > $topValue) {
            $pickedUp = [...$this->centerStack, ...$playedValues];
            $seat->hand = [...($seat->hand ?? []), ...$pickedUp];
            $seat->save();
            $this->centerStack = [];
            $this->lastMessage = "{$name} played too high and picked up ".count($pickedUp).' cards.';
            $this->finishTurnWithExtraTurn();

            return;
        }

        $this->centerStack = [...$this->centerStack, ...$playedValues];
        $this->lastMessage = "{$name} played {$count} \u{d7} {$label}".($count > 1 ? 's' : '').'.';
        $this->turnSeatIndex = ($this->turnSeatIndex + 1) % count($this->seats);

        if ($this->hasGoneOut($seat)) {
            $this->endRound($seatIndex);

            return;
        }

        $this->syncOnlineState();
        $this->reloadMyHand();
    }

    private function finishTurnWithExtraTurn(): void
    {
        // turnSeatIndex intentionally unchanged -- the acting seat gets an
        // additional turn, per the appendix's "Play"/"Wild" sections.
        $this->syncOnlineState();
        $this->reloadMyHand();
    }

    private function hasGoneOut(RoomSeat $seat): bool
    {
        return ($seat->hand ?? []) === []
            && ($seat->table_face_up ?? []) === []
            && ($seat->table_face_down ?? []) === [];
    }

    private function trailingRunLength(int $value): int
    {
        $run = 0;

        for ($i = count($this->centerStack) - 1; $i >= 0; $i--) {
            if ($this->centerStack[$i] !== $value) {
                break;
            }

            $run++;
        }

        return $run;
    }

    /**
     * Scores every other seat's remaining cards (Ace=1, 2-9 face value,
     * Jack=11, Queen=12, King=13, Ten=25 wherever it remains -- see
     * docs/specs/clear-the-deck.md section 3) and adds it to their
     * cumulative total; the seat that went out scores 0 this round.
     */
    private function endRound(int $wentOutSeatIndex): void
    {
        $roomSeats = RoomSeat::where('room_id', $this->resolveRoom()->id)->get()->keyBy('seat_index');

        $roundScores = [];

        foreach ($roomSeats as $seatIndex => $roomSeat) {
            if ($seatIndex === $wentOutSeatIndex) {
                $roundScores[$seatIndex] = 0;

                continue;
            }

            $cards = [...($roomSeat->hand ?? []), ...($roomSeat->table_face_up ?? []), ...($roomSeat->table_face_down ?? [])];
            $roundScores[$seatIndex] = array_sum(array_map(self::cardPoints(...), $cards));
        }

        foreach ($roundScores as $seatIndex => $points) {
            $this->scores[$seatIndex] = ($this->scores[$seatIndex] ?? 0) + $points;
        }

        $this->roundsWonCounts[$wentOutSeatIndex] = ($this->roundsWonCounts[$wentOutSeatIndex] ?? 0) + 1;
        $this->roundScores = $roundScores;
        $this->wentOutSeatIndex = $wentOutSeatIndex;
        $this->lastMessage = $this->seatName($wentOutSeatIndex).' went out!';

        if ($this->currentRound >= $this->roundTarget) {
            $this->phase = ClearTheDeckPhase::GameEnd;
            $this->finishGame();
        } else {
            $this->phase = ClearTheDeckPhase::RoundEnd;
        }

        $this->syncOnlineState();
        $this->reloadMyHand();
    }

    private function finishGame(): void
    {
        $session = GameSession::findOrFail($this->gameSessionId);
        $session->update(['ended_at' => now()]);
        $this->resolveRoom()->update(['status' => RoomStatus::Finished]);

        $roomSeats = RoomSeat::where('room_id', $this->resolveRoom()->id)->get();

        $minScore = min($this->scores);
        $winningSeatIndexes = collect($this->scores)->filter(fn (int $s) => $s === $minScore)->keys()->all();
        $isTie = count($winningSeatIndexes) > 1;

        foreach ($roomSeats as $roomSeat) {
            $score = $this->scores[$roomSeat->seat_index] ?? 0;
            $isWinningScore = in_array($roomSeat->seat_index, $winningSeatIndexes, true);

            $result = match (true) {
                $isWinningScore && $isTie => GameResult::Draw,
                $isWinningScore => GameResult::Win,
                default => GameResult::Loss,
            };

            GameParticipant::create([
                'game_session_id' => $session->id,
                'user_id' => $roomSeat->user_id,
                'guest_name' => null,
                'score' => $score,
                'result' => $result,
                'stats' => ['rounds_won' => $this->roundsWonCounts[$roomSeat->seat_index] ?? 0],
            ]);
        }

        $this->winnerName = collect($winningSeatIndexes)->map(fn (int $i) => $this->seatName($i))->join(', ');
    }

    /**
     * Any seated player may advance -- a shared "we're ready to continue"
     * decision, not a turn, same reasoning as Best Match's own nextRound().
     * Rotates the dealer, re-deals a fresh 20 cards to every seat, and
     * resets the center stack.
     */
    public function nextRound(): void
    {
        abort_unless($this->mySeatIndex !== null, 403);
        abort_unless($this->phase === ClearTheDeckPhase::RoundEnd, 403);

        $seatCount = count($this->seats);

        $this->dealerSeatIndex = ($this->dealerSeatIndex + 1) % $seatCount;
        $this->turnSeatIndex = ($this->dealerSeatIndex + 1) % $seatCount;
        $this->currentRound++;

        $this->dealSeats(range(0, $seatCount - 1));

        $this->centerStack = [];
        $this->roundScores = [];
        $this->wentOutSeatIndex = null;
        $this->lastMessage = null;
        $this->phase = ClearTheDeckPhase::Playing;

        $this->syncOnlineState();
        $this->reloadMyHand();
    }

    /**
     * Deals a fresh 20-card hand (4 face-down, 4 face-up, 12-card hand) to
     * every given seat from a freshly shuffled virtual deck. Duplicated
     * (not shared) with pages::clear-the-deck.room's own identical initial
     * deal -- see that file's own docblock on dealSeats() for why.
     *
     * @param  list<int>  $seatIndexes
     */
    private function dealSeats(array $seatIndexes): void
    {
        $seatCount = count($seatIndexes);
        $totalCards = 20 * $seatCount;
        $fullSets = (int) ceil($totalCards / 13);

        $deck = collect();

        for ($i = 0; $i < $fullSets; $i++) {
            $deck->push(...range(1, 13));
        }

        $deck = $deck->shuffle()->take($totalCards)->values();

        foreach ($seatIndexes as $seatIndex) {
            RoomSeat::where('room_id', $this->resolveRoom()->id)->where('seat_index', $seatIndex)->update([
                'table_face_down' => $deck->splice(0, 4)->values()->all(),
                'table_face_up' => $deck->splice(0, 4)->values()->all(),
                'hand' => $deck->splice(0, 12)->values()->all(),
            ]);
        }
    }

    private function reloadMyHand(): void
    {
        if ($this->mySeatIndex === null) {
            return;
        }

        $seat = RoomSeat::where('room_id', $this->resolveRoom()->id)
            ->where('seat_index', $this->mySeatIndex)
            ->first();

        $this->myHand = $seat->hand ?? [];
    }

    private function seatName(int $seatIndex): ?string
    {
        return collect($this->seats)->firstWhere('seatIndex', $seatIndex)['name'] ?? null;
    }

    private static function cardPoints(int $value): int
    {
        return $value === 10 ? 25 : $value;
    }

    private static function cardLabel(int $value): string
    {
        return match ($value) {
            1 => 'Ace',
            10 => 'Ten',
            11 => 'Jack',
            12 => 'Queen',
            13 => 'King',
            default => (string) $value,
        };
    }

    public function cardLabelFor(int $value): string
    {
        return self::cardLabel($value);
    }
};
?>

<x-slot:title>Clear the Deck — Low Stim Play</x-slot>
<x-slot:description>A shared center pile, private hands, and table cards hidden even from their own owner until played -- a turn-based shedding game for 3-10 players.</x-slot:description>
<x-slot:bodyClass>clear-the-deck-play</x-slot:bodyClass>
<x-slot:fullscreenToggle>1</x-slot:fullscreenToggle>
<x-slot:rulesTitle>Clear the Deck Rules</x-slot:rulesTitle>
<x-slot:rules>
    <h3>How to Use This Page</h3>
    <ul>
        <li>Every player joins from their own device -- there's no shared/host screen, since hands and face-down cards are private.</li>
        <li>On your turn, select cards of the same value from your hand and/or your own face-up table cards, and choose how many of your own remaining face-down cards to flip and add in -- then play.</li>
        <li>Four or more matching cards clears the shared pile and gives you another turn. Playing higher than the pile's current top card makes you pick the whole pile up into your hand, but you also get another turn. Otherwise, play passes to the next player.</li>
        <li>Tens are wild -- playing one always clears the pile.</li>
    </ul>

    <h3>Full Rules</h3>
    <ul>
        <li>Each player starts a round with a 12-card hand, 4 face-up table cards, and 4 face-down table cards whose identity stays hidden -- even from that player -- until played.</li>
        <li>A face-down card can only be flipped once it's no longer covered by one of your own face-up cards.</li>
        <li>The round ends the instant any player empties their hand and both table piles; everyone else scores the value of every card they have left (Ace=1, 2-9 face value, Jack=11, Queen=12, King=13, Ten=25) and the player who went out scores 0.</li>
        <li>After every configured round is played, the lowest total score wins.</li>
    </ul>
</x-slot:rules>
<div>
    @if ($started)
        @teleport('#game-actions')
            <div style="display: contents;">
                @if ($phase === ClearTheDeckPhase::GameEnd)
                    <a href="{{ route('clear-the-deck.room', ['code' => $room]) }}"><button type="button">Back to room</button></a>
                @endif
            </div>
        @endteleport
    @endif

    @if ($room === null)
        <div class="setup" id="setup">
            <div class="heading">Clear the Deck</div>

            @if ($this->hostedRooms->isNotEmpty())
                <div class="flex flex-col items-center gap-2 w-full">
                    <p class="status-line">Play in a room you host:</p>
                    <div class="flex flex-wrap justify-center gap-2">
                        @foreach ($this->hostedRooms as $hostedRoom)
                            <a href="{{ route('clear-the-deck.room', ['code' => $hostedRoom->code]) }}" wire:key="hosted-room-{{ $hostedRoom->id }}">
                                <button type="button" id="hosted-room-{{ $hostedRoom->code }}">{{ $hostedRoom->code }}</button>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            <p class="status-line">Clear the Deck is online-only -- open <strong>Rooms</strong> in the top bar to create or join a room, then start Clear the Deck from there.</p>
        </div>
    @else
        <div id="clear-the-deck-game" class="flex flex-col items-center w-full" style="gap: inherit;">
            <div class="status-line">
                Round {{ $currentRound }} of {{ $roundTarget }} &middot; {{ $this->seatName($dealerSeatIndex) }} is dealing
            </div>

            @if ($lastMessage)
                <p class="status-line">{{ $lastMessage }}</p>
            @endif

            @unless ($phase === ClearTheDeckPhase::GameEnd)
                <div class="flex flex-wrap justify-center gap-3 w-full">
                    @foreach ($seats as $seat)
                        @php $table = $seatTables[$seat['seatIndex']] ?? ['handCount' => 0, 'tableFaceUp' => [], 'tableFaceDownCount' => 0] @endphp
                        <div wire:key="seat-{{ $seat['seatIndex'] }}" class="flex flex-col items-center gap-1 bg-surface border-2 border-surface-border rounded-lg px-4 py-2">
                            <span class="text-sm text-text/80">
                                {{ $seat['name'] }}
                                {{ $seat['seatIndex'] === $turnSeatIndex && $phase === ClearTheDeckPhase::Playing ? ' (turn)' : '' }}
                                {{ $seat['seatIndex'] === $dealerSeatIndex ? ' (dealer)' : '' }}
                            </span>
                            <span class="text-sm text-text">Score: {{ $scores[$seat['seatIndex']] ?? 0 }}</span>
                            <span class="text-xs text-text/65">Hand: {{ $table['handCount'] }}</span>
                            <span class="text-xs text-text/65">
                                Face up: {{ $table['tableFaceUp'] === [] ? 'none' : collect($table['tableFaceUp'])->map(fn ($v) => $this->cardLabelFor($v))->join(', ') }}
                            </span>
                            <span class="text-xs text-text/65">Face down: {{ $table['tableFaceDownCount'] }}</span>
                        </div>
                    @endforeach
                </div>

                <div class="panel">
                    <div class="panel-title">
                        {{ $centerStack === [] ? 'The pile is empty -- any value can be played.' : 'Top of pile: '.$this->cardLabelFor(end($centerStack)).' ('.count($centerStack).' cards)' }}
                    </div>
                </div>
            @endunless

            @if ($phase === ClearTheDeckPhase::Playing)
                @if ($this->isMyTurn)
                    <p class="status-line">Your turn -- select cards of the same value, then play:</p>

                    @if ($playError)
                        <p class="status-line" style="color: var(--warn);">{{ $playError }}</p>
                    @endif

                    <div class="flex flex-col items-center gap-2 w-full">
                        <p class="status-line">Hand</p>
                        <div class="controls">
                            @foreach ($myHand as $index => $value)
                                <button type="button" class="option {{ in_array($index, $selectedHandIndices, true) ? 'active' : '' }}" wire:key="hand-{{ $index }}" wire:click="toggleHandCard({{ $index }})">
                                    {{ $this->cardLabelFor($value) }}
                                </button>
                            @endforeach
                        </div>

                        <p class="status-line">Your face-up cards</p>
                        <div class="controls">
                            @forelse ($this->myTableFaceUp as $index => $value)
                                <button type="button" class="option {{ in_array($index, $selectedFaceUpIndices, true) ? 'active' : '' }}" wire:key="face-up-{{ $index }}" wire:click="toggleFaceUpCard({{ $index }})">
                                    {{ $this->cardLabelFor($value) }}
                                </button>
                            @empty
                                <span class="status-line">None left.</span>
                            @endforelse
                        </div>

                        <p class="status-line">Flip face-down cards ({{ $this->myUncoveredFaceDownCount }} available)</p>
                        <div class="stepper">
                            <button type="button" wire:click="decrementFaceDownSelection" @disabled($selectedFaceDownCount <= 0) aria-label="Fewer face-down cards">&minus;</button>
                            <div class="stepper-value">{{ $selectedFaceDownCount }}</div>
                            <button type="button" wire:click="incrementFaceDownSelection" @disabled($selectedFaceDownCount >= $this->myUncoveredFaceDownCount) aria-label="More face-down cards">+</button>
                        </div>

                        <div class="controls">
                            <button type="button" id="play-cards" wire:click="playCards">Play</button>
                        </div>
                    </div>
                @elseif ($this->isSpectator)
                    <p class="status-line">Watching -- {{ $this->seatName($turnSeatIndex) }}'s turn.</p>
                @else
                    <p class="status-line">Waiting for {{ $this->seatName($turnSeatIndex) }}...</p>
                @endif
            @endif

            @if ($phase === ClearTheDeckPhase::RoundEnd)
                <div class="panel">
                    <div class="panel-title">{{ $this->seatName($wentOutSeatIndex) }} went out!</div>
                    <div class="flex flex-wrap justify-center gap-3 w-full">
                        @foreach ($seats as $seat)
                            <div wire:key="round-score-{{ $seat['seatIndex'] }}" class="flex flex-col items-center gap-1 bg-surface border-2 border-surface-border rounded-lg px-4 py-2">
                                <span class="text-sm text-text/80">{{ $seat['name'] }}</span>
                                <span class="text-sm text-text">+{{ $roundScores[$seat['seatIndex']] ?? 0 }} this round</span>
                                <span class="text-xs text-text/65">{{ $scores[$seat['seatIndex']] ?? 0 }} total</span>
                            </div>
                        @endforeach
                    </div>
                    @if ($mySeatIndex !== null)
                        <div class="controls">
                            <button type="button" wire:click="nextRound">Next Round</button>
                        </div>
                    @endif
                </div>
            @endif

            @if ($phase === ClearTheDeckPhase::GameEnd)
                <div class="panel">
                    <div class="panel-title">Final Scores</div>
                    <div class="panel-title">{{ $winnerName }} wins!</div>
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
