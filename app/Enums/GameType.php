<?php

namespace App\Enums;

enum GameType: string
{
    case Boggle = 'boggle';
    case Yahtzee = 'yahtzee';
    case FamilyFeud = 'family_feud';

    /**
     * The user-visible display name -- deliberately not the same as the
     * enum case names above (`Boggle`/`Yahtzee`/`FamilyFeud`) or their
     * string values, which stay as they are internally (routes, DB
     * values, CSS classes, tests, ...). This is the one thing that
     * actually needed to change for `docs/TODO.md`'s former #1 ("rename
     * the games everywhere a user can see the name") -- "in code isn't
     * important" per that TODO's own wording, so nothing else did.
     */
    public function label(): string
    {
        return match ($this) {
            self::Boggle => 'Word Grid',
            self::Yahtzee => 'Yacht',
            self::FamilyFeud => 'Survey Says',
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
