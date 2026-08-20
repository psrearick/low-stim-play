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

test('Quartiles has no room mode', function () {
    expect(GameType::Quartiles->supportsRooms())->toBeFalse();

    foreach (GameType::cases() as $type) {
        if ($type === GameType::Quartiles) {
            continue;
        }

        expect($type->supportsRooms())->toBeTrue();
    }
});

test('roomRoute() throws for Quartiles instead of returning a route that does not exist', function () {
    expect(fn () => GameType::Quartiles->roomRoute())->toThrow(LogicException::class);
});
