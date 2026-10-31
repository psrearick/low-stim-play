<?php

namespace App\Enums;

enum RoomStatus: string
{
    case Lobby = 'lobby';
    case Active = 'active';
    /**
     * No longer written -- a finished game now puts the room straight back
     * to Lobby (Room::finishCurrentGame()). Kept so rooms that finished a
     * game before that change still cast.
     */
    case Finished = 'finished';
}
