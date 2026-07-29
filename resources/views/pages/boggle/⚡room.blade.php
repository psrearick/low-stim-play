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

<div class="flex flex-col items-center gap-6 w-full">
    <h1 class="text-accent font-bold tracking-wide text-3xl">Boggle room</h1>

    @if ($this->isHost)
        <div class="flex flex-col items-center gap-4 w-full max-w-md bg-surface border-2 border-surface-border rounded-lg p-6">
            <div class="flex flex-col items-center gap-2 w-full">
                <p class="text-sm text-text/80">Board size</p>
                <div class="flex gap-2">
                    @foreach ([4, 5] as $option)
                        <button type="button" wire:click="$set('size', {{ $option }})"
                            class="rounded px-6 py-2 font-bold tracking-wide border-2 {{ $size === $option ? 'bg-accent text-bg border-accent' : 'bg-surface text-text border-surface-border' }}">
                            {{ $option }}&times;{{ $option }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="flex flex-col items-center gap-2 w-full">
                <p class="text-sm text-text/80">Time limit</p>
                <div class="flex flex-wrap justify-center gap-2">
                    @foreach ([1, 2, 3, 5] as $option)
                        <button type="button" wire:click="$set('minutes', {{ $option }})"
                            class="rounded px-4 py-2 font-bold tracking-wide border-2 {{ $minutes === $option ? 'bg-accent text-bg border-accent' : 'bg-surface text-text border-surface-border' }}">
                            {{ $option }} min
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <livewire:game-lobby :room="$room" />
</div>
