<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Enums\YahtzeeCategory;
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
    public Room $room;

    /**
     * Host-only, chosen on this lobby before starting (docs/concerns.md)
     * -- baked into the GameSession's own settings in onRoomStarting()
     * below, the same way Boggle's own $size/$minutes are. Only the
     * host's own request ever creates the session, so only their choice
     * matters; other members never see this toggle at all (see the Blade
     * below).
     */
    public bool $undoEnabled = false;

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

        // Arriving here directly (a shared room URL) rather than through the
        // /yahtzee setup screen's own "Join" form -- that form already
        // provisions a guest before redirecting here, but this route has no
        // auth middleware of its own (see routes/web.php), so a first-time
        // direct visitor still needs one.
        if (Auth::guest()) {
            Auth::login(User::createGuest(request()->cookie('guest_name')));

            // Guarded rather than unconditional -- Livewire::test() drives
            // this component directly, without the full HTTP kernel/session
            // middleware a real request goes through, so there's no session
            // store attached to request() there.
            if (request()->hasSession()) {
                request()->session()->regenerate();
            }
        }

        $this->room->addMember(Auth::user());

        // The game may already be running by the time anyone actually
        // lands here -- a reconnecting seated player whose device was
        // disconnected at the exact moment the host clicked "Start" would
        // otherwise never receive RoomGameStarting (onRoomStarted() below
        // only reacts to that broadcast arriving *after* mount, which
        // never happens for them) and would be stuck looking at this
        // now-stale lobby forever, even though the game is running fine
        // for everyone else. This also doubles as the only real entry
        // point into the spectator/host view (see pages::yahtzee.play):
        // anyone -- seated or not -- who visits this room URL once the
        // game has started is sent straight into it.
        $this->redirectIfAlreadyStarted();

        // Reflects whatever's already true rather than assuming this is a
        // fresh visit -- the host reloading after already clicking Open
        // Lobby (or a member arriving after that already happened) both
        // need to land past the config screen, not back at square one.
        $this->lobbyOpened = $this->room->pendingGameType() === GameType::Yahtzee;
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

        $this->room->markPendingGame(GameType::Yahtzee);
        RoomGameSwitched::dispatch($this->room, GameType::Yahtzee);
        $this->lobbyOpened = true;
    }

    public function getIsHostProperty(): bool
    {
        return Auth::id() === $this->room->host_user_id;
    }

    private function redirectIfAlreadyStarted(): void
    {
        if ($this->room->status === RoomStatus::Active && $this->room->game_session_id !== null) {
            $this->goToGame();
        }
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return [
            "echo-presence:room.{$this->room->code},RoomGameStarting" => 'onRoomStarted',
            "echo-presence:room.{$this->room->code},RoomGameSwitched" => 'onGameSwitchedElsewhere',
            // Local (non-broadcast) event dispatched by the nested GameLobby
            // when its host clicks "Start game" -- only the host's own
            // request reaches this, since GameLobby::startGame() aborts for
            // anyone else. Handling it here (rather than in GameLobby
            // itself) keeps GameLobby game-agnostic; Yahtzee owns deciding
            // what "starting" means for Yahtzee (seat assignment + an
            // initial shared scorecard state).
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
        $this->lobbyOpened = $this->room->pendingGameType() === GameType::Yahtzee;
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

        return $pending !== null && $pending !== GameType::Yahtzee ? $pending : null;
    }

    /**
     * @param  array<int, array{id: int, name: string, is_host: bool, ready: bool}>  $players
     */
    public function onRoomStarting(array $players): void
    {
        $this->room->refresh();

        if ($this->room->game_session_id === null) {
            $seats = array_values($players);

            $emptyScores = array_fill_keys(
                array_map(fn (YahtzeeCategory $category) => $category->value, YahtzeeCategory::all()),
                null,
            );

            $state = [
                'seats' => array_map(
                    fn (array $player, int $index) => [
                        'seatIndex' => $index,
                        'name' => $player['name'],
                        'scores' => $emptyScores,
                        'yahtzeeBonusCount' => 0,
                    ],
                    $seats,
                    array_keys($seats),
                ),
                'currentSeat' => 0,
                'dice' => [],
                'kept' => [],
                'rollsLeft' => 3,
                'gameOver' => false,
            ];

            $session = GameSession::create([
                'type' => GameType::Yahtzee,
                'mode' => GameMode::Online,
                'settings' => ['player_count' => count($seats), 'undo_enabled' => $this->undoEnabled],
                'state' => $state,
                'started_at' => now(),
            ]);

            // Cleared first, not just created -- a room can be replayed
            // (game-lobby's startGame() resets game_session_id to null on
            // every "Start Game" click, not just the first) or switched to
            // a different game entirely and back (see GameType::roomRoute()
            // and pages::room.show's own startGame()), and this branch
            // runs again either way. Without this, a second Yahtzee game in
            // the same room left the *previous* game's seat rows in place
            // and just added a second set on top, so every seat-scoped
            // query (mySeatIndex, seatUserIds in endGame(), ...) then saw
            // duplicate rows for the same room/seat_index.
            RoomSeat::where('room_id', $this->room->id)->delete();

            foreach ($seats as $index => $player) {
                RoomSeat::create([
                    'room_id' => $this->room->id,
                    'seat_index' => $index,
                    'user_id' => $player['id'],
                ]);
            }

            $this->room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);

            // Broadcast only now that game_session_id/seats are actually
            // set -- see pages::boggle.room::onRoomStarting() for why this
            // ordering (write first, broadcast second) matters: a guest
            // reacting to the broadcast needs the DB write to already be
            // visible when it checks.
            RoomGameStarting::dispatch($this->room);
        }

        $this->goToGame();
    }

    public function onRoomStarted(): void
    {
        $this->room->refresh();
        $this->redirectIfAlreadyStarted();
    }

    private function goToGame(): void
    {
        $this->redirect(route('yahtzee.play', ['room' => $this->room->code]));
    }
};
?>

<x-slot:title>Yacht Room — Low Stim Play</x-slot>

<div class="flex flex-col items-center" style="gap: inherit;">
    <div class="heading">Yacht Room</div>

    {{--
        Host-only (docs/concerns.md) -- same "settings step before
        <livewire:game-lobby>" pattern Boggle's own room page uses for its
        board size/time limit, the first time Yahtzee has needed one.
    --}}
    @if ($this->switchedElsewhere)
        <div class="setup">
            <p class="status-line">The host switched to {{ $this->switchedElsewhere->label() }}.</p>
            <a href="{{ route($this->switchedElsewhere->roomRoute(), ['code' => $room->code]) }}">
                <button type="button">Join</button>
            </a>
        </div>
    @elseif ($lobbyOpened)
        <livewire:game-lobby :room="$room" :current-game="GameType::Yahtzee" />
    @elseif ($this->isHost)
        <div class="setup">
            <div class="setup-row">
                <div class="label">Undo</div>
                <div class="option-group">
                    <button type="button" class="option {{ ! $undoEnabled ? 'active' : '' }}" wire:click="$set('undoEnabled', false)">Off</button>
                    <button type="button" class="option {{ $undoEnabled ? 'active' : '' }}" wire:click="$set('undoEnabled', true)">On</button>
                </div>
            </div>

            <button type="button" id="open-lobby-btn" wire:click="openLobby">Open Lobby</button>
        </div>
    @else
        <div class="setup">
            <p class="status-line">Waiting for the host to finish setting up&hellip;</p>
        </div>
    @endif
</div>
