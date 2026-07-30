<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast whenever any one player's game in a shared Boggle room ends --
 * by choice ("End Game") or by their own timer running out -- so every
 * other player's still-running game ends too instead of some players
 * finishing on a different clock than the one who ended first. Carries
 * only the room code (a string, not the Room model) since that's all
 * pages::boggle.play already has on hand and all broadcastOn() needs.
 * ShouldBroadcastNow, not ShouldBroadcast, for the same reason as
 * RoomPlayerReadyToggled/RoomGameStarting -- this has to arrive
 * immediately, not whenever a queue worker next polls.
 */
class RoomGameEnded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $roomCode,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel("room.{$this->roomCode}"),
        ];
    }
}
