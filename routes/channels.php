<?php

use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

$authorizeRoomPresence = function (User $user, string $code) {
    $room = Room::notExpired()->where('code', $code)->first();

    if (! $room) {
        return null;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
        'is_host' => $room->host_user_id === $user->id,
    ];
};

Broadcast::channel('room.{code}', $authorizeRoomPresence);

// Only <livewire:game-lobby> joins this one -- see its getListeners() for
// why lobby presence is kept separate from the room-wide channel above.
Broadcast::channel('room.{code}.lobby', $authorizeRoomPresence);
