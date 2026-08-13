<?php

namespace App\Enums;

enum ClearTheDeckPhase: string
{
    case Playing = 'playing';
    case RoundEnd = 'round_end';
    case GameEnd = 'game_end';
}
