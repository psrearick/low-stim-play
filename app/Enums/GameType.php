<?php

namespace App\Enums;

enum GameType: string
{
    case Boggle = 'boggle';
    case Yahtzee = 'yahtzee';
    case FamilyFeud = 'family_feud';

    public function label(): string
    {
        return match ($this) {
            self::Boggle => 'Boggle',
            self::Yahtzee => 'Yahtzee',
            self::FamilyFeud => 'Family Feud',
        };
    }
}
