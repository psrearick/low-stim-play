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

test('Quartiles and Wordle have no room mode', function () {
    expect(GameType::Quartiles->supportsRooms())->toBeFalse()
        ->and(GameType::Wordle->supportsRooms())->toBeFalse();

    foreach (GameType::cases() as $type) {
        if (in_array($type, [GameType::Quartiles, GameType::Wordle], true)) {
            continue;
        }

        expect($type->supportsRooms())->toBeTrue();
    }
});

test('roomRoute() throws for Quartiles and Wordle instead of returning a route that does not exist', function () {
    expect(fn () => GameType::Quartiles->roomRoute())->toThrow(LogicException::class)
        ->and(fn () => GameType::Wordle->roomRoute())->toThrow(LogicException::class);
});

test('only Boggle, Quartiles, and Wordle are challengeable today', function () {
    expect(GameType::Boggle->isChallengeable())->toBeTrue()
        ->and(GameType::Quartiles->isChallengeable())->toBeTrue()
        ->and(GameType::Wordle->isChallengeable())->toBeTrue();

    foreach (GameType::cases() as $type) {
        if (in_array($type, [GameType::Boggle, GameType::Quartiles, GameType::Wordle], true)) {
            continue;
        }

        expect($type->isChallengeable())->toBeFalse();
    }
});
