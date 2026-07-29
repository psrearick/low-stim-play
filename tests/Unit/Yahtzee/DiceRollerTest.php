<?php

use App\Services\Yahtzee\DiceRoller;

beforeEach(function () {
    $this->roller = new DiceRoller;
});

test('the initial roll produces 5 dice, each between 1 and 6', function () {
    $dice = $this->roller->initial();

    expect($dice)->toHaveCount(5);

    foreach ($dice as $value) {
        expect($value)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(6);
    }
});

test('rerolling only changes dice that are not kept', function () {
    $current = [1, 2, 3, 4, 5];
    $kept = [true, true, false, false, true];

    $rerolled = $this->roller->reroll($current, $kept);

    expect($rerolled[0])->toBe(1)
        ->and($rerolled[1])->toBe(2)
        ->and($rerolled[4])->toBe(5)
        ->and($rerolled[2])->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(6)
        ->and($rerolled[3])->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(6);
});

test('keeping every die changes nothing', function () {
    $current = [6, 6, 6, 6, 6];
    $kept = [true, true, true, true, true];

    expect($this->roller->reroll($current, $kept))->toBe($current);
});
