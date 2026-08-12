<?php

namespace App\Enums;

enum AppleMatchPhase: string
{
    case Submitting = 'submitting';
    case Judging = 'judging';
    case RoundEnd = 'round_end';
    case GameEnd = 'game_end';
}
