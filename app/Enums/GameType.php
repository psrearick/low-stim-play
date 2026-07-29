<?php

namespace App\Enums;

enum GameType: string
{
    case Boggle = 'boggle';
    case Yahtzee = 'yahtzee';
    case FamilyFeud = 'family_feud';
}
