<?php

namespace App\Enums;

enum GameMode: string
{
    case Solo = 'solo';
    case Local = 'local';
    case Online = 'online';
    case Async = 'async';
}
