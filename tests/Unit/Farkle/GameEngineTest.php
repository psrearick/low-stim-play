<?php

use App\Services\Farkle\GameEngine;
use App\Services\Farkle\Scorer;

beforeEach(function () {
    $this->engine = new GameEngine(new Scorer);
});

test('scoreSelection, hasAnyScoringDice, and scoreMaximal delegate to the Scorer', function () {
    expect($this->engine->scoreSelection([1, 1, 1]))->toBe(['score' => 1000, 'valid' => true])
        ->and($this->engine->hasAnyScoringDice([2, 2, 3, 3, 4, 6]))->toBeFalse()
        ->and($this->engine->scoreMaximal([4, 4, 4, 2, 1]))->toBe(500);
});

test('a turn scoring at least the minimum entry always meets it', function () {
    expect($this->engine->meetsMinimumEntry(false, 500))->toBeTrue()
        ->and($this->engine->meetsMinimumEntry(false, 750))->toBeTrue();
});

test('a turn under the minimum entry only meets it once the player has already qualified', function () {
    expect($this->engine->meetsMinimumEntry(false, 450))->toBeFalse()
        ->and($this->engine->meetsMinimumEntry(true, 450))->toBeTrue();
});
