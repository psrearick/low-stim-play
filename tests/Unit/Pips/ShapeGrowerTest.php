<?php

use App\Enums\PipsDifficulty;
use App\Services\Pips\Grid;
use App\Services\Pips\ShapeGrower;
use Random\Engine\Mt19937;
use Random\Randomizer;

dataset('difficulties', fn () => PipsDifficulty::cases());

test('grows exactly the target cell count', function (PipsDifficulty $difficulty) {
    $shape = (new ShapeGrower)->grow(new Randomizer(new Mt19937(1)), $difficulty->rows(), $difficulty->cols(), $difficulty->cellCount());

    expect($shape['cells'])->toHaveCount($difficulty->cellCount());
})->with('difficulties');

test('the shape is a single connected blob', function (PipsDifficulty $difficulty) {
    $shape = (new ShapeGrower)->grow(new Randomizer(new Mt19937(2)), $difficulty->rows(), $difficulty->cols(), $difficulty->cellCount());
    $neighbors = Grid::activeNeighbors($shape['dimensions']['rows'], $shape['dimensions']['cols'], $shape['cells']);

    $visited = [];
    $stack = [$shape['cells'][0]];

    while ($stack !== []) {
        $cell = array_pop($stack);

        if (isset($visited[$cell])) {
            continue;
        }

        $visited[$cell] = true;

        foreach ($neighbors[$cell] as $neighbor) {
            if (! isset($visited[$neighbor])) {
                $stack[] = $neighbor;
            }
        }
    }

    expect($visited)->toHaveCount(count($shape['cells']));
})->with('difficulties');

test('the tight bounding box has no empty border row or column', function (PipsDifficulty $difficulty) {
    $shape = (new ShapeGrower)->grow(new Randomizer(new Mt19937(3)), $difficulty->rows(), $difficulty->cols(), $difficulty->cellCount());
    $rows = $shape['dimensions']['rows'];
    $cols = $shape['dimensions']['cols'];

    $usedRows = array_unique(array_map(fn (int $cell) => intdiv($cell, $cols), $shape['cells']));
    $usedCols = array_unique(array_map(fn (int $cell) => $cell % $cols, $shape['cells']));

    expect(min($usedRows))->toBe(0);
    expect(max($usedRows))->toBe($rows - 1);
    expect(min($usedCols))->toBe(0);
    expect(max($usedCols))->toBe($cols - 1);
})->with('difficulties');

test('the shape is checkerboard-balanced (a necessary condition for domino tileability)', function (PipsDifficulty $difficulty) {
    $shape = (new ShapeGrower)->grow(new Randomizer(new Mt19937(4)), $difficulty->rows(), $difficulty->cols(), $difficulty->cellCount());
    $cols = $shape['dimensions']['cols'];

    $even = 0;
    $odd = 0;

    foreach ($shape['cells'] as $cell) {
        $row = intdiv($cell, $cols);
        $col = $cell % $cols;

        (($row + $col) % 2 === 0) ? $even++ : $odd++;
    }

    expect($even)->toBe($odd);
})->with('difficulties');

test('the same seed always produces the same shape', function (PipsDifficulty $difficulty) {
    $grower = new ShapeGrower;

    $a = $grower->grow(new Randomizer(new Mt19937(77)), $difficulty->rows(), $difficulty->cols(), $difficulty->cellCount());
    $b = $grower->grow(new Randomizer(new Mt19937(77)), $difficulty->rows(), $difficulty->cols(), $difficulty->cellCount());

    expect($a)->toEqual($b);
})->with('difficulties');

test('the shape is not always a filled rectangle', function () {
    // Confirms real irregularity, not an algorithm that incidentally always
    // produces a rectangle -- across enough seeds, at least one grown shape
    // must have fewer cells than its own tight bounding box's area.
    $grower = new ShapeGrower;
    $foundIrregular = false;

    foreach (range(1, 25) as $seed) {
        $shape = $grower->grow(new Randomizer(new Mt19937($seed)), PipsDifficulty::Hard->rows(), PipsDifficulty::Hard->cols(), PipsDifficulty::Hard->cellCount());
        $area = $shape['dimensions']['rows'] * $shape['dimensions']['cols'];

        if ($area > count($shape['cells'])) {
            $foundIrregular = true;
            break;
        }
    }

    expect($foundIrregular)->toBeTrue();
});
