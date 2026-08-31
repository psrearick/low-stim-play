<?php

use App\Enums\GameType;

test('every room game except Boggle supports spectating', function () {
    expect(GameType::Boggle->supportsSpectating())->toBeFalse();

    // Filtered to supportsRooms() -- Quartiles has no room mode at all, so
    // asserting anything about its own supportsSpectating() value here
    // wouldn't mean anything (nothing ever calls it for a room-less game).
    foreach (GameType::cases() as $type) {
        if ($type === GameType::Boggle || ! $type->supportsRooms()) {
            continue;
        }

        expect($type->supportsSpectating())->toBeTrue();
    }
});

test('Quartiles, Wordle, SpellingBee, and Pips have no room mode', function () {
    expect(GameType::Quartiles->supportsRooms())->toBeFalse()
        ->and(GameType::Wordle->supportsRooms())->toBeFalse()
        ->and(GameType::SpellingBee->supportsRooms())->toBeFalse()
        ->and(GameType::Pips->supportsRooms())->toBeFalse();

    foreach (GameType::cases() as $type) {
        if (in_array($type, [GameType::Quartiles, GameType::Wordle, GameType::SpellingBee, GameType::Pips], true)) {
            continue;
        }

        expect($type->supportsRooms())->toBeTrue();
    }
});

test('roomRoute() throws for Quartiles, Wordle, SpellingBee, and Pips instead of returning a route that does not exist', function () {
    expect(fn () => GameType::Quartiles->roomRoute())->toThrow(LogicException::class)
        ->and(fn () => GameType::Wordle->roomRoute())->toThrow(LogicException::class)
        ->and(fn () => GameType::SpellingBee->roomRoute())->toThrow(LogicException::class)
        ->and(fn () => GameType::Pips->roomRoute())->toThrow(LogicException::class);
});

test('roomRoute() maps every room-capable case to its own room route name', function () {
    expect(GameType::Boggle->roomRoute())->toBe('boggle.room')
        ->and(GameType::Yahtzee->roomRoute())->toBe('yahtzee.room')
        ->and(GameType::FamilyFeud->roomRoute())->toBe('family-feud.room')
        ->and(GameType::Farkle->roomRoute())->toBe('farkle.room')
        ->and(GameType::AppleMatch->roomRoute())->toBe('apple-match.room')
        ->and(GameType::ClearTheDeck->roomRoute())->toBe('clear-the-deck.room')
        ->and(GameType::Punchline->roomRoute())->toBe('punchline.room');
});

test('only Boggle, Quartiles, Wordle, SpellingBee, and Pips are challengeable today', function () {
    expect(GameType::Boggle->isChallengeable())->toBeTrue()
        ->and(GameType::Quartiles->isChallengeable())->toBeTrue()
        ->and(GameType::Wordle->isChallengeable())->toBeTrue()
        ->and(GameType::SpellingBee->isChallengeable())->toBeTrue()
        ->and(GameType::Pips->isChallengeable())->toBeTrue();

    foreach (GameType::cases() as $type) {
        if (in_array($type, [GameType::Boggle, GameType::Quartiles, GameType::Wordle, GameType::SpellingBee, GameType::Pips], true)) {
            continue;
        }

        expect($type->isChallengeable())->toBeFalse();
    }
});

test('playRoute() maps Pips to its own play route name', function () {
    expect(GameType::Pips->playRoute())->toBe('pips.play');
});
