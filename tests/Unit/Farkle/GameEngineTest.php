<?php

use App\Services\Farkle\GameEngine;
use App\Services\Farkle\Scorer;

beforeEach(function () {
    $this->engine = new GameEngine(new Scorer);
});

test('scoreSelection, hasAnyScoringDice, and scoreMaximal delegate to the Scorer', function () {
    expect($this->engine->scoreSelection([1, 1, 1]))->toBe(['score' => 300, 'valid' => true])
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

test('scoreSelection and scoreMaximal pass Spicy flags through to the Scorer', function () {
    expect($this->engine->scoreSelection([4, 4, 4], [false, false, true]))->toBe(['score' => 800, 'valid' => true])
        // Three 4s doubled (800) plus the single 1 (100, not spicy) = 900.
        ->and($this->engine->scoreMaximal([4, 4, 4, 2, 1], [false, false, true, false, false]))->toBe(900);
});

test('isInstantWin is true only when every one of a full six-die roll is spicy', function () {
    expect($this->engine->isInstantWin([true, true, true, true, true, true]))->toBeTrue()
        ->and($this->engine->isInstantWin([true, true, true, true, true, false]))->toBeFalse()
        // A partial reroll of fewer than 6 can never satisfy "all six".
        ->and($this->engine->isInstantWin([true, true, true]))->toBeFalse()
        ->and($this->engine->isInstantWin([]))->toBeFalse();
});
