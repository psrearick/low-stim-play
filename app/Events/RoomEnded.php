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
 * The host ended the room entirely (pages::room.show's endRoom()) -- every
 * other member currently viewing the room hub needs to be sent away from
 * it, the same ShouldBroadcastNow reasoning RoomGameSwitched's own docblock
 * gives: without this, or without a queue worker, everyone but the host
 * would be left looking at a room page for a room that no longer exists.
 */
class RoomEnded implements ShouldBroadcastNow
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
}
