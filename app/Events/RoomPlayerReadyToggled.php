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
 * ShouldBroadcastNow, not ShouldBroadcast -- this needs to reach every other
 * player in the room the instant it's dispatched, not whenever a queue
 * worker next picks it up. A queued broadcast is invisible if no worker is
 * running at all (an easy thing to forget to provision on a host separate
 * from the web process, e.g. Laravel Cloud) and, even with one running,
 * adds a real (if often sub-second) delay that has no reason to exist for
 * an event this small and this latency-sensitive.
 */
class RoomPlayerReadyToggled implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Room $room,
        public int $userId,
        public bool $ready,
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
            'user_id' => $this->userId,
            'ready' => $this->ready,
        ];
    }
}
