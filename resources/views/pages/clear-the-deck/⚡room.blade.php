<?php

use App\Enums\ClearTheDeckPhase;
use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Events\RoomGameStarting;
use App\Events\RoomGameSwitched;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public const int MIN_PLAYERS = 3;

    public const int MAX_PLAYERS = 10;

    public const int MIN_ROUNDS = 1;

    public const int MAX_ROUNDS = 10;

    public const int DEFAULT_ROUNDS = 1;

    public Room $room;

    public int $roundTarget = self::DEFAULT_ROUNDS;

    /**
     * Set only on the host's own connection when onRoomStarting() below
     * declines to actually start -- 'room-game-starting' is a local,
     * non-broadcast dispatch, so this never reaches anyone else, same
     * reasoning as $startError on pages::apple-match.room.
     */
    public ?string $startError = null;

    /**
     * Gates <livewire:game-lobby> -- false the entire time the host is
     * still on this page's own config fields above, so nobody else gets
     * notified/can join until the host explicitly opens the lobby (see
     * openLobby() below). Re-derived from Room::pendingGameType() on every
     * mount() rather than stored anywhere new, so a reload or a return
     * visit lands back in the right phase automatically.
     */
    public bool $lobbyOpened = false;

    public function mount(string $code): void
    {
        $this->room = Room::notExpired()->where('code', strtoupper($code))->firstOrFail();

        // Arriving here directly (a shared room URL), rather than through a
        // local setup screen -- Clear the Deck has no pass-and-play mode
        // (see docs/specs/clear-the-deck.md section 1), same reasoning as
        // pages::apple-match.room.
        if (Auth::guest()) {
            Auth::login(User::createGuest(request()->cookie('guest_name')));

            if (request()->hasSession()) {
                request()->session()->regenerate();
            }
        }

        $this->room->addMember(Auth::user());

        $this->redirectIfAlreadyStarted();

        // Reflects whatever's already true rather than assuming this is a
        // fresh visit -- the host reloading after already clicking Open
        // Lobby (or a member arriving after that already happened) both
        // need to land past the config screen, not back at square one.
        $this->lobbyOpened = $this->room->pendingGameType() === GameType::ClearTheDeck;
    }

    /**
     * The explicit moment this room's config screen turns into the real
     * waiting room -- everyone else was previously notified/able to join
     * the instant the host so much as loaded this page, before touching a
     * single setting (docs/concerns.md). markPendingGame()/RoomGameSwitched
     * moved here from both this page's own mount() and pages::room.show's
     * startGame(), so nothing fires until the host deliberately confirms.
     */
    public function openLobby(): void
    {
        abort_unless($this->isHost, 403);

        $this->room->markPendingGame(GameType::ClearTheDeck);
        RoomGameSwitched::dispatch($this->room, GameType::ClearTheDeck);
        $this->lobbyOpened = true;
    }

    private function redirectIfAlreadyStarted(): void
    {
        if ($this->room->status === RoomStatus::Active && $this->room->game_session_id !== null) {
            $this->goToGame();
        }
    }

    public function setRoundTarget(int $target): void
    {
        $this->roundTarget = max(self::MIN_ROUNDS, min(self::MAX_ROUNDS, $target));
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return [
            "echo-presence:room.{$this->room->code},RoomGameStarting" => 'onRoomStarted',
            "echo-presence:room.{$this->room->code},RoomGameSwitched" => 'onGameSwitchedElsewhere',
            'room-game-starting' => 'onRoomStarting',
        ];
    }

    /**
     * Recomputes $lobbyOpened for whoever's already sitting on this exact
     * page -- covers both the host opening this same room in a second tab
     * after already clicking Open Lobby elsewhere, and a non-host who
     * loaded this page before the host had (see getSwitchedElsewhereProperty()
     * below for the case where the broadcast is for a *different* game).
     */
    public function onGameSwitchedElsewhere(): void
    {
        $this->room->refresh();
        $this->lobbyOpened = $this->room->pendingGameType() === GameType::ClearTheDeck;
    }

    /**
     * Mirrors <livewire:game-lobby>'s own getSwitchedGameTypeProperty() --
     * the equivalent case for a visitor still on *this* page's own
     * pre-lobby-open screens (config or the non-host waiting message),
     * which game-lobby itself can't cover since it isn't rendered yet.
     */
    public function getSwitchedElsewhereProperty(): ?GameType
    {
        $pending = $this->room->pendingGameType();

        return $pending !== null && $pending !== GameType::ClearTheDeck ? $pending : null;
    }

    /**
     * Deals the first round's cards and creates the shared game state.
     * Enforces the 3-10 player range from docs/specs/clear-the-deck.md
     * section 3 with a host-visible error, same reasoning as
     * pages::apple-match.room's own MIN_PLAYERS guard.
     *
     * @param  array<int, array{id: int, name: string, is_host: bool, ready: bool}>  $players
     */
    public function onRoomStarting(array $players): void
    {
        $this->room->refresh();

        if ($this->room->game_session_id !== null) {
            $this->goToGame();

            return;
        }

        $seats = array_values($players);
        $seatCount = count($seats);

        if ($seatCount < self::MIN_PLAYERS || $seatCount > self::MAX_PLAYERS) {
            $this->startError = 'Clear the Deck (Tens) needs between '.self::MIN_PLAYERS.' and '.self::MAX_PLAYERS
                ." players to start \u{2014} ".$seatCount.' here right now.';

            return;
        }

        $this->startError = null;

        // Cleared first, not just created -- a room can be replayed or
        // switched to a different game and back (see GameType::roomRoute()),
        // and this branch runs again either way. Same reasoning as every
        // other game's own onRoomStarting().
        RoomSeat::where('room_id', $this->room->id)->delete();

        foreach ($seats as $index => $player) {
            RoomSeat::create([
                'room_id' => $this->room->id,
                'seat_index' => $index,
                'user_id' => $player['id'],
            ]);
        }

        $seatIndexes = range(0, $seatCount - 1);
        $this->dealSeats($seatIndexes);

        $roomSeats = RoomSeat::where('room_id', $this->room->id)->orderBy('seat_index')->get()->keyBy('seat_index');

        $state = [
            'roundTarget' => $this->roundTarget,
            'currentRound' => 1,
            'seats' => array_map(
                fn (array $player, int $index) => ['seatIndex' => $index, 'name' => $player['name']],
                $seats,
                array_keys($seats),
            ),
            'scores' => array_fill(0, $seatCount, 0),
            'roundsWonCounts' => array_fill(0, $seatCount, 0),
            'dealerSeatIndex' => 0,
            // The player to the left of the dealer begins (appendix
            // "Play") -- always seat 1, since self::MIN_PLAYERS = 3
            // guarantees at least 2 other seats exist.
            'turnSeatIndex' => 1,
            'centerStack' => [],
            'phase' => ClearTheDeckPhase::Playing->value,
            'lastMessage' => null,
            'roundScores' => [],
            'wentOutSeatIndex' => null,
            'winnerName' => null,
            'seatTables' => collect($seatIndexes)->mapWithKeys(fn (int $i) => [$i => [
                'handCount' => count($roomSeats[$i]->hand ?? []),
                'tableFaceUp' => $roomSeats[$i]->table_face_up ?? [],
                'tableFaceDownCount' => count($roomSeats[$i]->table_face_down ?? []),
            ]])->all(),
        ];

        $session = GameSession::create([
            'type' => GameType::ClearTheDeck,
            'mode' => GameMode::Online,
            'settings' => ['round_target' => $this->roundTarget],
            'state' => $state,
            'started_at' => now(),
        ]);

        $this->room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);

        RoomGameStarting::dispatch($this->room);

        $this->goToGame();
    }

    /**
     * Deals a fresh 20-card hand (4 face-down, 4 face-up, 12-card hand) to
     * every given seat from a freshly shuffled virtual deck -- "standard 13
     * values, repeating as many times as needed" rather than literally
     * modeling multiple 52-card decks, per docs/specs/clear-the-deck.md
     * section 2/4. No suits: a card is just an int 1-13 (Ace low, King
     * high). Duplicated (not shared) with pages::clear-the-deck.play's own
     * identical redeal in nextRound() -- same "simple Eloquent queries, not
     * a reusable engine class" precedent Best Match's own dealing set (see
     * docs/DEVLOG.md's Best Match section).
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
            RoomSeat::where('room_id', $this->room->id)->where('seat_index', $seatIndex)->update([
                'table_face_down' => $deck->splice(0, 4)->values()->all(),
                'table_face_up' => $deck->splice(0, 4)->values()->all(),
                'hand' => $deck->splice(0, 12)->values()->all(),
            ]);
        }
    }

    public function onRoomStarted(): void
    {
        $this->room->refresh();
        $this->redirectIfAlreadyStarted();
    }

    public function getIsHostProperty(): bool
    {
        return Auth::id() === $this->room->host_user_id;
    }

    private function goToGame(): void
    {
        $this->redirect(route('clear-the-deck.play', ['room' => $this->room->code]));
    }
};
?>

<x-slot:title>Clear the Deck (Tens) Room — Low Stim Play</x-slot>

<div class="flex flex-col items-center" style="gap: inherit;">
    <div class="heading">Clear the Deck (Tens) Room</div>

    @if ($this->switchedElsewhere)
        <div class="setup">
            <p class="status-line">The host switched to {{ $this->switchedElsewhere->label() }}.</p>
            <a href="{{ route($this->switchedElsewhere->roomRoute(), ['code' => $room->code]) }}">
                <button type="button">Join</button>
            </a>
        </div>
    @elseif ($lobbyOpened)
        @if ($startError)
            <p class="status-line" style="color: var(--warn);">{{ $startError }}</p>
        @endif

        <livewire:game-lobby :room="$room" :current-game="GameType::ClearTheDeck" />
    @elseif ($this->isHost)
        <div class="setup">
            <div class="setup-row">
                <div class="label">Rounds</div>
                <div class="stepper">
                    <button type="button" wire:click="setRoundTarget({{ $roundTarget - 1 }})" @disabled($roundTarget <= self::MIN_ROUNDS) aria-label="Fewer rounds">&minus;</button>
                    <div class="stepper-value">{{ $roundTarget }}</div>
                    <button type="button" wire:click="setRoundTarget({{ $roundTarget + 1 }})" @disabled($roundTarget >= self::MAX_ROUNDS) aria-label="More rounds">+</button>
                </div>
            </div>
            <div class="setup-note">Lowest total score after this many rounds wins. Needs {{ self::MIN_PLAYERS }}&ndash;{{ self::MAX_PLAYERS }} players to start.</div>

            <button type="button" id="open-lobby-btn" wire:click="openLobby">Open Lobby</button>
        </div>
    @else
        <div class="setup">
            <p class="status-line">Waiting for the host to finish setting up&hellip;</p>
        </div>
    @endif
</div>
