<?php

use App\Services\Pips\ConstraintDeriver;
use Random\Engine\Mt19937;
use Random\Randomizer;

test('every derived constraint is actually true of the region it came from', function () {
    $deriver = new ConstraintDeriver;

    $regions = [
        0 => [0],
        1 => [1, 2],
        2 => [3, 4, 5],
        3 => [6, 7, 8, 9],
    ];
    $cellValues = [0 => 3, 1 => 2, 2 => 2, 3 => 1, 4 => 5, 5 => 6, 6 => 0, 7 => 0, 8 => 1, 9 => 2];

    for ($seed = 0; $seed < 200; $seed++) {
        $constraints = $deriver->derive($regions, $cellValues, new Randomizer(new Mt19937($seed)));

        foreach ($regions as $regionId => $cells) {
            $values = array_map(fn (int $cell) => $cellValues[$cell], $cells);
            $sum = array_sum($values);
            $constraint = $constraints[$regionId];

            match ($constraint['type']) {
                'sum' => expect($constraint['value'])->toBe($sum),
                'equal' => expect(array_unique($values))->toHaveCount(1),
                'different' => expect(array_unique($values))->toHaveCount(count($values)),
                'greater' => expect($sum)->toBeGreaterThan($constraint['value']),
                'less' => expect($sum)->toBeLessThan($constraint['value']),
                'none' => null,
                default => throw new RuntimeException("Unexpected constraint type [{$constraint['type']}]."),
            };
        }
    }
});

test('a single-cell region only ever gets sum or none, never equal/different', function () {
    $deriver = new ConstraintDeriver;
    $regions = [0 => [0]];
    $cellValues = [0 => 4];

    for ($seed = 0; $seed < 100; $seed++) {
        $constraints = $deriver->derive($regions, $cellValues, new Randomizer(new Mt19937($seed)));

        expect($constraints[0]['type'])->toBeIn(['sum', 'none']);
    }
});

test('the same seed always produces the same constraints', function () {
    $deriver = new ConstraintDeriver;
    $regions = [0 => [0, 1], 1 => [2, 3, 4]];
    $cellValues = [0 => 1, 1 => 1, 2 => 2, 3 => 3, 4 => 4];

    $a = $deriver->derive($regions, $cellValues, new Randomizer(new Mt19937(123)));
    $b = $deriver->derive($regions, $cellValues, new Randomizer(new Mt19937(123)));

    expect($a)->toEqual($b);
});
