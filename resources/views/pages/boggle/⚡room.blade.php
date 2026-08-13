<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Events\RoomGameStarting;
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

        // Lets a member who loads the room hub after the host already
        // landed here (missing the real-time RoomGameSwitched broadcast
        // entirely) still discover and join this lobby -- see Room::
        // markPendingGame()'s own docblock.
        if (Auth::id() === $this->room->host_user_id && $this->room->game_session_id === null) {
            $this->room->markPendingGame(GameType::Boggle);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return [
            "echo-presence:room.{$this->room->code},RoomGameStarting" => 'onRoomStarted',
            // Local (non-broadcast) event dispatched by the nested GameLobby
            // when its host clicks "Start game" -- only the host's own
            // request reaches this, since GameLobby::startGame() aborts for
            // anyone else. Handling it here (rather than in GameLobby
            // itself) keeps GameLobby game-agnostic; Boggle owns deciding
            // what "starting" means for Boggle (generating a shared board).
            'room-game-starting' => 'onRoomStarting',
        ];
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

    @if ($this->isHost)
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
        </div>
    @endif

    <livewire:game-lobby :room="$room" :current-game="GameType::Boggle" />
</div>
