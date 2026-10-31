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
 * The room's current game is over and the room is back between games
 * (Room::finishCurrentGame()) -- game-agnostic, unlike Boggle's own
 * RoomGameEnded (which exists to cascade-end every Boggle player's own
 * still-running timer). Lets the room hub and every results screen's
 * <livewire:room-new-game-notice> switch to "what's next?" the moment it
 * happens instead of only after the host opens the next lobby.
 * ShouldBroadcastNow for the same reason as RoomGameStarting.
 */
class RoomGameFinished implements ShouldBroadcastNow
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
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'code' => $this->room->code,
        ];
    }
}
