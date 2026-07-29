<?php

namespace App\Enums;

enum RoomStatus: string
{
    case Lobby = 'lobby';
    case Active = 'active';
    case Finished = 'finished';
}
