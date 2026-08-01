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
 * Generic, game-agnostic "the shared game state changed" broadcast for
 * turn-based online rooms (Yahtzee today; Family Feud's own follow-up
 * reuses this as-is for its own state shape) -- carries the *entire*
 * current state blob rather than a delta, mirroring this app's existing
 * preference for simplicity over premature optimization (the payload is
 * always small: a handful of seats' scores/dice/turn pointer). See
 * RoomPlayerReadyToggled for why this is ShouldBroadcastNow rather than
 * ShouldBroadcast -- every other seat needs this the instant it's
 * dispatched, not whenever a queue worker next polls.
 */
class RoomGameStateUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $state
     */
    public function __construct(
        public Room $room,
        public array $state,
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
            'state' => $this->state,
        ];
    }
}
