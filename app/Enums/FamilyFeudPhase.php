<?php

namespace App\Enums;

enum FamilyFeudPhase: string
{
    case Play = 'play';
    case Steal = 'steal';
    case RoundEnd = 'round_end';
    case RoundSummary = 'round_summary';
    case Manual = 'manual';
    case GameEnd = 'game_end';
}
