<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Models\GameSession;
use App\Models\Room;
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

<x-slot:title>Boggle Room — Low Stim Play</x-slot>

<div class="flex flex-col items-center" style="gap: inherit;">
    <div class="heading">Boggle Room</div>

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

    <livewire:game-lobby :room="$room" />
</div>
