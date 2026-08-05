<?php

use App\Services\Farkle\DiceRoller;

beforeEach(function () {
    $this->diceRoller = new DiceRoller;
});

test('rolling N dice returns exactly N real die faces', function () {
    $values = $this->diceRoller->roll(6);

    expect($values)->toHaveCount(6);

    foreach ($values as $value) {
        expect($value)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(6);
    }
});

test('rolling fewer dice returns exactly that many', function () {
    expect($this->diceRoller->roll(2))->toHaveCount(2)
        ->and($this->diceRoller->roll(1))->toHaveCount(1);
});

test('rolling zero dice returns an empty list, not PHP range()\'s descending-count quirk', function () {
    expect($this->diceRoller->roll(0))->toBe([]);
});
