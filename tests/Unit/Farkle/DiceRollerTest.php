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

test('rollSlots returns one value and one spicy flag per requested slot', function () {
    $result = $this->diceRoller->rollSlots([1, 2, 3, 4, 5, 6]);

    expect($result['values'])->toHaveCount(6)
        ->and($result['spicy'])->toHaveCount(6);

    foreach ($result['values'] as $value) {
        expect($value)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(6);
    }
});

test('rollSlots only requests as many dice as it was given slots for', function () {
    $result = $this->diceRoller->rollSlots([3, 5]);

    expect($result['values'])->toHaveCount(2)
        ->and($result['spicy'])->toHaveCount(2);
});

test('rollSlots returns an empty result for an empty slot list', function () {
    expect($this->diceRoller->rollSlots([]))->toBe(['values' => [], 'spicy' => []]);
});

test('rollSlots flags a die spicy exactly when it lands on its own slot label', function () {
    // Run enough times to be confident both true and false actually
    // occur (each is ~5/6 vs ~1/6 per die per roll) rather than getting
    // lucky/unlucky on a single random roll.
    $sawSpicy = false;
    $sawNotSpicy = false;

    for ($i = 0; $i < 200; $i++) {
        $result = $this->diceRoller->rollSlots([1, 2, 3, 4, 5, 6]);

        foreach ($result['values'] as $index => $value) {
            $slot = $index + 1;
            $spicy = $result['spicy'][$index];

            // The defining invariant, checked every time regardless of
            // which way it landed: spicy iff the rolled value equals this
            // die's own slot label.
            expect($spicy)->toBe($value === $slot);

            $spicy ? $sawSpicy = true : $sawNotSpicy = true;
        }
    }

    expect($sawSpicy)->toBeTrue()->and($sawNotSpicy)->toBeTrue();
});
