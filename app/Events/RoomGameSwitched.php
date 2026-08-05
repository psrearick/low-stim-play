<?php

namespace App\Events;

use App\Enums\GameType;
use App\Models\Room;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The host chose a different game to play in this room (see
 * <livewire:game-lobby>'s switchGame()) -- every other present player
 * needs to follow along to that game's own room page automatically, the
 * same reasoning RoomGameStarting's own docblock gives for ShouldBroadcastNow
 * over ShouldBroadcast: without this, or without a queue worker running,
 * everyone but the host would be silently stranded on the game they were
 * just switched away from.
 */
class RoomGameSwitched implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Room $room,
        public GameType $type,
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
            'type' => $this->type->value,
        ];
    }
}
