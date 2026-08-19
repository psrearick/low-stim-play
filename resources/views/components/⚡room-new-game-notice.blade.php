<?php

use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Models\Room;
use Livewire\Component;

/**
 * Shared across every room game's own play page (mirrors <livewire:
 * game-lobby>'s "one game-agnostic component, every game drops it in"
 * shape) -- tells a visitor still sitting on *this* page's own game
 * (finished or not) that the room has actually moved on to a different
 * session since this page loaded, whether that's a fresh round of the
 * same game or a switch to a different one. Without this, a player
 * lingering on a just-ended game's reveal screen had no way to find out a
 * new round had already started elsewhere short of guessing to click
 * "Back to room" themselves.
 */
new class extends Component
{
    public ?Room $room = null;

    public GameType $currentGame;

    public ?int $myGameSessionId = null;

    public function mount(string $roomCode, GameType $currentGame, ?int $myGameSessionId = null): void
    {
        // Nullable, not firstOrFail() -- this is a purely informational
        // widget dropped onto an already-working page; a room that's
        // somehow gone by the time this mounts should just render nothing,
        // not take the whole page down with it.
        $this->room = Room::where('code', $roomCode)->first();
        $this->currentGame = $currentGame;
        $this->myGameSessionId = $myGameSessionId;
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        if ($this->room === null) {
            return [];
        }

        $channel = "room.{$this->room->code}";

        return [
            "echo-presence:{$channel},RoomGameStarting" => 'onRoomChanged',
            "echo-presence:{$channel},RoomGameSwitched" => 'onRoomChanged',
        ];
    }

    public function onRoomChanged(): void
    {
        $this->room?->refresh();
    }

    /**
     * The game now active in this room, if it's genuinely a *different*
     * session than the one this page itself is displaying -- i.e. the
     * room has moved on since this page's own game session was loaded,
     * whether or not this visitor is still watching that now-superseded
     * game. Null the entire time nothing's changed, including while this
     * page's own game is still the room's current one (the ordinary case
     * for every render before/during a normal game).
     */
    public function getSupersededByProperty(): ?GameType
    {
        if ($this->room === null || $this->room->status !== RoomStatus::Active || $this->room->game_session_id === null) {
            return null;
        }

        if ($this->room->game_session_id === $this->myGameSessionId) {
            return null;
        }

        return $this->room->gameSession->type;
    }
};
?>
<div>
    @if ($this->supersededBy)
        <div class="setup w-full" style="margin-bottom: clamp(8px, 1.4vh, 16px);">
            @if ($this->supersededBy->supportsSpectating())
                <p class="status-line">The host started a new {{ $this->supersededBy->label() }} game.</p>
                <a href="{{ route($this->supersededBy->playRoute(), ['room' => $room->code]) }}">
                    <button type="button">Watch</button>
                </a>
            @else
                {{-- No RoomSeat/spectator concept for this game (Boggle) --
                     see GameType::supportsSpectating()'s own docblock. --}}
                <p class="status-line">{{ $this->supersededBy->label() }} is being played right now -- check back to join the next game.</p>
            @endif
        </div>
    @endif
</div>
