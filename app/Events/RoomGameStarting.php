<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ShouldBroadcastNow, not ShouldBroadcast -- see RoomPlayerReadyToggled's
 * own note. This one matters even more: without it, the *only* way other
 * players ever learn the host started the game is this broadcast, so a
 * queue worker that isn't running (or isn't deployed at all) means the
 * game silently never starts for anyone but the host.
 */
class RoomGameStarting implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Room $room,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel("room.{$this->room->code}"),
        ];
    }

    /**
     * Explicit and minimal, rather than the default (every public property,
     * including the full serialized $room model) -- every existing listener
     * (each game's own onRoomStarted(), <livewire:game-lobby>'s own
     * onGameStarting()) takes no arguments and just re-reads $this->room
     * itself, and <livewire:rooms-menu>'s own onGameStarting() (docs/
     * concerns.md #1) needs to tell *which* of a user's several rooms this
     * is for.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'code' => $this->room->code,
        ];
    }
}
