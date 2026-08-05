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

    /**
     * The named route for this game's own online-room page (`pages::
     * {game}.room`) -- the single place that maps a game type to "where do
     * I send a room's players to play this," used when a room switches
     * from one game to another (see `<livewire:game-lobby>`'s
     * `switchGame()`) so that logic doesn't need its own copy of this
     * mapping, or any game-specific knowledge at all.
     */
    public function roomRoute(): string
    {
        return match ($this) {
            self::Boggle => 'boggle.room',
            self::Yahtzee => 'yahtzee.room',
            self::FamilyFeud => 'family-feud.room',
        };
    }
}
