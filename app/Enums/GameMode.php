<?php

namespace App\Enums;

enum GameMode: string
{
    case Solo = 'solo';
    case Local = 'local';
    case Online = 'online';
    case Async = 'async';

    public function label(): string
    {
        return match ($this) {
            self::Solo => 'Solo',
            self::Local => 'Local',
            self::Online => 'Online Room',
            self::Async => 'Async Challenge',
        };
    }
}
