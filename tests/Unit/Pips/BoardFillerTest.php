<?php

use App\Enums\PipsDifficulty;
use App\Services\Pips\BoardFiller;
use Random\Engine\Mt19937;
use Random\Randomizer;

dataset('difficulties', fn () => PipsDifficulty::cases());

test('fills every cell exactly once', function (PipsDifficulty $difficulty) {
    $result = (new BoardFiller)->fill(new Randomizer(new Mt19937(1)), $difficulty->rows(), $difficulty->cols(), $difficulty->maxPip());

    expect($result['cellValues'])->toHaveCount($difficulty->cellCount());
    expect(array_keys($result['cellValues']))->toEqual(range(0, $difficulty->cellCount() - 1));

    foreach ($result['cellValues'] as $value) {
        expect($value)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual($difficulty->maxPip());
    }
})->with('difficulties');

test('uses every domino from the full set exactly once', function (PipsDifficulty $difficulty) {
    $result = (new BoardFiller)->fill(new Randomizer(new Mt19937(2)), $difficulty->rows(), $difficulty->cols(), $difficulty->maxPip());

    expect($result['placements'])->toHaveCount($difficulty->dominoCount());

    $seen = [];
    foreach ($result['placements'] as $placement) {
        $key = min($placement['valueA'], $placement['valueB']).'-'.max($placement['valueA'], $placement['valueB']);
        expect($seen)->not->toHaveKey($key);
        $seen[$key] = true;
    }

    expect($seen)->toHaveCount($difficulty->dominoCount());
})->with('difficulties');

test('every placement covers two orthogonally adjacent cells', function (PipsDifficulty $difficulty) {
    $result = (new BoardFiller)->fill(new Randomizer(new Mt19937(3)), $difficulty->rows(), $difficulty->cols(), $difficulty->maxPip());

    foreach ($result['placements'] as $placement) {
        $rowA = intdiv($placement['cellA'], $difficulty->cols());
        $colA = $placement['cellA'] % $difficulty->cols();
        $rowB = intdiv($placement['cellB'], $difficulty->cols());
        $colB = $placement['cellB'] % $difficulty->cols();

        expect(abs($rowA - $rowB) + abs($colA - $colB))->toBe(1);
    }
})->with('difficulties');

test('the same seed always produces the same fill', function (PipsDifficulty $difficulty) {
    $filler = new BoardFiller;

    $a = $filler->fill(new Randomizer(new Mt19937(99)), $difficulty->rows(), $difficulty->cols(), $difficulty->maxPip());
    $b = $filler->fill(new Randomizer(new Mt19937(99)), $difficulty->rows(), $difficulty->cols(), $difficulty->maxPip());

    expect($a)->toEqual($b);
})->with('difficulties');
