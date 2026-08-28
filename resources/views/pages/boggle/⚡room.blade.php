<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Events\RoomGameStarting;
use App\Events\RoomGameSwitched;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\User;
use App\Services\Boggle\BoardGenerator;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public Room $room;

    public int $size = 4;

    public int $minutes = 3;

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

        // Arriving here directly (a shared room URL) rather than through
        // boggle-lobby's own "Join" form -- that form already provisions a
        // guest before redirecting here, but this route has no auth
        // middleware of its own (see routes/web.php), so a first-time
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

        // Reflects whatever's already true rather than assuming this is a
        // fresh visit -- the host reloading after already clicking Open
        // Lobby (or a member arriving after that already happened) both
        // need to land past the config screen, not back at square one.
        $this->lobbyOpened = $this->room->pendingGameType() === GameType::Boggle;
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

        $this->room->markPendingGame(GameType::Boggle);
        RoomGameSwitched::dispatch($this->room, GameType::Boggle);
        $this->lobbyOpened = true;
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
            // itself) keeps GameLobby game-agnostic; Boggle owns deciding
            // what "starting" means for Boggle (generating a shared board).
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
        $this->lobbyOpened = $this->room->pendingGameType() === GameType::Boggle;
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

        return $pending !== null && $pending !== GameType::Boggle ? $pending : null;
    }

    public function onRoomStarting(BoardGenerator $boardGenerator): void
    {
        $this->room->refresh();

        if ($this->room->game_session_id === null) {
            $board = $boardGenerator->generate($this->size);

            $session = GameSession::create([
                'type' => GameType::Boggle,
                'mode' => GameMode::Online,
                'settings' => ['size' => $this->size, 'seed' => $board['seed'], 'minutes' => $this->minutes],
                'started_at' => now(),
            ]);

            $this->room->update(['game_session_id' => $session->id]);

            // Broadcast only now that game_session_id is actually set, not
            // from GameLobby::startGame() (where it used to live) -- that
            // dispatch is a ShouldBroadcastNow event, so it reaches other
            // players' onRoomStarted() listener (below) near-instantly.
            // Firing it before this row was ever written meant a guest
            // could receive "the game is starting" and check
            // game_session_id before this same host's own follow-up
            // request had set it, see null, and silently do nothing --
            // stranding them on the room page. This is a *different*
            // Livewire request/response cycle than GameLobby::startGame()
            // (reached via its local, non-broadcast 'room-game-starting'
            // dispatch, see getListeners() above), so this ordering is the
            // only way to guarantee the DB write happens first.
            RoomGameStarting::dispatch($this->room);
        }

        $this->goToGame();
    }

    public function onRoomStarted(): void
    {
        $this->room->refresh();

        if ($this->room->status === RoomStatus::Active && $this->room->game_session_id !== null) {
            $this->goToGame();
        }
    }

    public function getIsHostProperty(): bool
    {
        return Auth::id() === $this->room->host_user_id;
    }

    private function goToGame(): void
    {
        $settings = $this->room->gameSession->settings;

        $this->redirect(route('boggle.play', [
            'size' => $settings['size'],
            'minutes' => $settings['minutes'],
            'seed' => $settings['seed'],
            'room' => $this->room->code,
        ]));
    }
};
?>

<x-slot:title>Word Grid Room — Low Stim Play</x-slot>

<div class="flex flex-col items-center" style="gap: inherit;">
    <div class="heading">Word Grid Room</div>

    @if ($this->switchedElsewhere)
        <div class="setup">
            <p class="status-line">The host switched to {{ $this->switchedElsewhere->label() }}.</p>
            <a href="{{ route($this->switchedElsewhere->roomRoute(), ['code' => $room->code]) }}">
                <button type="button">Join</button>
            </a>
        </div>
    @elseif ($lobbyOpened)
        <livewire:game-lobby :room="$room" :current-game="GameType::Boggle" />
    @elseif ($this->isHost)
        <div class="setup">
            <div class="setup-row">
                <div class="label">Board Size</div>
                <div class="option-group">
                    @foreach ([4, 5] as $option)
                        <button type="button" class="option {{ $size === $option ? 'active' : '' }}" wire:click="$set('size', {{ $option }})">
                            {{ $option === 4 ? 'Classic 4x4' : 'Big 5x5' }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="setup-row">
                <div class="label">Time Limit</div>
                <div class="option-group">
                    @foreach ([1, 2, 3, 5] as $option)
                        <button type="button" class="option {{ $minutes === $option ? 'active' : '' }}" wire:click="$set('minutes', {{ $option }})">
                            {{ $option }} min
                        </button>
                    @endforeach
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
