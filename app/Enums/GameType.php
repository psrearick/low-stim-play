<?php

namespace App\Enums;

enum GameType: string
{
    case Boggle = 'boggle';
    case Yahtzee = 'yahtzee';
    case FamilyFeud = 'family_feud';
    case Farkle = 'farkle';
    case AppleMatch = 'apple_match';
    case ClearTheDeck = 'clear_the_deck';
    case Quartiles = 'quartiles';
    case Wordle = 'wordle';
    case SpellingBee = 'spelling_bee';
    case Punchline = 'punchline';

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
            self::Farkle => '10,000',
            self::AppleMatch => 'Best Match',
            self::ClearTheDeck => 'Clear the Deck (Tens)',
            self::Quartiles => 'Quads',
            self::Wordle => 'Five Letters',
            self::SpellingBee => 'Word Hive',
            self::Punchline => 'Punchline',
        };
    }

    /**
     * The named route for this game's own online-room page (`pages::
     * {game}.room`) -- the single place that maps a game type to "where do
     * I send a room's players to play this," used when a room switches
     * from one game to another (see `<livewire:game-lobby>`'s
     * `switchGame()`) so that logic doesn't need its own copy of this
     * mapping, or any game-specific knowledge at all. `self::Quartiles` has
     * no room mode at all (see `supportsRooms()`) and is filtered out of
     * every list that would otherwise call this -- its own arm throws
     * rather than returning a route name that doesn't exist, so callers
     * that skip the `supportsRooms()` filter fail loudly instead of
     * routing somewhere broken.
     */
    public function roomRoute(): string
    {
        return match ($this) {
            self::Boggle => 'boggle.room',
            self::Yahtzee => 'yahtzee.room',
            self::FamilyFeud => 'family-feud.room',
            self::Farkle => 'farkle.room',
            self::AppleMatch => 'apple-match.room',
            self::ClearTheDeck => 'clear-the-deck.room',
            self::Quartiles => throw new \LogicException('Quartiles has no online-room mode; check supportsRooms() before calling roomRoute().'),
            self::Wordle => throw new \LogicException('Wordle has no online-room mode; check supportsRooms() before calling roomRoute().'),
            self::SpellingBee => throw new \LogicException('SpellingBee has no online-room mode; check supportsRooms() before calling roomRoute().'),
            self::Punchline => 'punchline.room',
        };
    }

    /**
     * The named route for this game's own online-play page (`pages::
     * {game}.play`) -- used by pages::room.show to link a member straight
     * into a room's currently active game (resuming their own seat, or
     * spectating) without detouring through the lobby/seat-assignment
     * page roomRoute() above points at. Unlike `roomRoute()` above, this
     * *is* exhaustive over every case including `self::Quartiles` --
     * `/quads` is a real, valid solo page, so mapping it costs
     * nothing even though nothing currently calls `playRoute()` for a
     * Quartiles instance in practice (both call sites derive their
     * `GameType` from an active room's game, and Quartiles never has one).
     */
    public function playRoute(): string
    {
        return match ($this) {
            self::Boggle => 'boggle.play',
            self::Yahtzee => 'yahtzee.play',
            self::FamilyFeud => 'family-feud.play',
            self::Farkle => 'farkle.play',
            self::AppleMatch => 'apple-match.play',
            self::ClearTheDeck => 'clear-the-deck.play',
            self::Quartiles => 'quartiles.play',
            self::Wordle => 'wordle.play',
            self::SpellingBee => 'spelling-bee.play',
            self::Punchline => 'punchline.play',
        };
    }

    /**
     * Whether this game has an online-room mode at all. False for
     * `self::Quartiles`/`self::Wordle`/`self::SpellingBee` -- unlike every
     * other game (including solo-capable Boggle), none of them has a
     * multiplayer mode to opt into, not even one that's merely unavailable
     * the way Best Match/Clear the Deck lack a pass-and-play mode. Used to
     * filter `GameType::cases()` everywhere a room might list "other games
     * to switch to" (see `<livewire:game-lobby>`'s
     * `getOtherGamesProperty()`), so `roomRoute()` above never actually
     * needs any of these cases.
     */
    public function supportsRooms(): bool
    {
        return ! in_array($this, [self::Quartiles, self::Wordle, self::SpellingBee], true);
    }

    /**
     * Whether a room member with no `RoomSeat` of their own can still open
     * this game's play page mid-game as a read-only watcher, rather than
     * being offered nothing at all. True for every seat-based room game
     * (Yahtzee/FamilyFeud/Farkle/AppleMatch/ClearTheDeck all already treat
     * a seatless viewer as an implicit spectator -- some with an explicit
     * `$spectate` toggle, some just by gating every action behind
     * `$mySeatIndex !== null` and rendering read-only otherwise). False
     * only for Boggle, which has no seats or shared live board at all --
     * each player's play-through is its own independent Livewire instance
     * that would actually start a fresh playthrough on "join," not watch
     * one, so joining mid-game has to be withheld entirely rather than
     * mislabeled as spectating. Used by pages::room.show's active-game
     * banner and the shared `<livewire:room-new-game-notice>` component so
     * neither ever offers a "Watch"/"Join" link into a game that can't
     * actually honor it.
     */
    public function supportsSpectating(): bool
    {
        return $this !== self::Boggle;
    }

    /**
     * Whether this game has a "Challenge a Friend" link worth emailing --
     * true only for games with a reproducible/shareable solo puzzle
     * (Boggle's own async challenge link, Word Blocks' seed-based share
     * link, Five Letters' own shared/ranked session link -- see
     * docs/specs/wordle.md section 3, the same shared-session model Boggle
     * uses rather than Word Blocks' plain seed link, since Five Letters'
     * answer is secret and can't just be regenerated from a public seed).
     * The extensibility hook for Task 10's future NYT-style games: add a
     * case here once one gets its own shareable puzzle code, nothing else
     * needs to change (see GameChallengeService::linkFor()).
     */
    public function isChallengeable(): bool
    {
        return in_array($this, [self::Boggle, self::Quartiles, self::Wordle, self::SpellingBee], true);
    }
}
