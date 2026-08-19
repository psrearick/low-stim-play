<?php

use App\Enums\GameType;

test('every room game except Boggle supports spectating', function () {
    expect(GameType::Boggle->supportsSpectating())->toBeFalse();

    foreach (GameType::cases() as $type) {
        if ($type === GameType::Boggle) {
            continue;
        }

        expect($type->supportsSpectating())->toBeTrue();
    }
});
