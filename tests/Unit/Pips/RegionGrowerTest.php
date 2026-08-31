<?php

use App\Enums\PipsDifficulty;
use App\Services\Pips\Grid;
use App\Services\Pips\RegionGrower;
use App\Services\Pips\ShapeGrower;
use Random\Engine\Mt19937;
use Random\Randomizer;

dataset('difficulties', fn () => PipsDifficulty::cases());

/**
 * RegionGrower operates over a board's real, possibly-irregular active cell
 * set (see its own docblock), so every test here grows a real shape first
 * via ShapeGrower rather than assuming a dense rows()/cols() rectangle is
 * itself the board -- exercising the same integration PuzzleGenerator's own
 * orchestration relies on, including coverage/contiguity around holes.
 */
test('every cell is covered by exactly one region', function (PipsDifficulty $difficulty) {
    $randomizer = new Randomizer(new Mt19937(1));
    $shape = (new ShapeGrower)->grow($randomizer, $difficulty->rows(), $difficulty->cols(), $difficulty->cellCount());
    $regions = (new RegionGrower)->grow($randomizer, $shape['dimensions']['rows'], $shape['dimensions']['cols'], $shape['cells']);

    $seen = [];
    foreach ($regions as $cells) {
        foreach ($cells as $cell) {
            expect($seen)->not->toHaveKey((string) $cell);
            $seen[$cell] = true;
        }
    }

    expect($seen)->toHaveCount(count($shape['cells']));
    expect(array_keys($seen))->toEqualCanonicalizing($shape['cells']);
})->with('difficulties');

test('every region is a single contiguous blob', function (PipsDifficulty $difficulty) {
    $randomizer = new Randomizer(new Mt19937(2));
    $shape = (new ShapeGrower)->grow($randomizer, $difficulty->rows(), $difficulty->cols(), $difficulty->cellCount());
    $regions = (new RegionGrower)->grow($randomizer, $shape['dimensions']['rows'], $shape['dimensions']['cols'], $shape['cells']);
    $neighbors = Grid::activeNeighbors($shape['dimensions']['rows'], $shape['dimensions']['cols'], $shape['cells']);

    foreach ($regions as $cells) {
        $inRegion = array_flip($cells);
        $visited = [];
        $stack = [$cells[0]];

        while ($stack !== []) {
            $cell = array_pop($stack);

            if (isset($visited[$cell])) {
                continue;
            }

            $visited[$cell] = true;

            foreach ($neighbors[$cell] as $neighbor) {
                if (isset($inRegion[$neighbor]) && ! isset($visited[$neighbor])) {
                    $stack[] = $neighbor;
                }
            }
        }

        expect($visited)->toHaveCount(count($cells));
    }
})->with('difficulties');

test('the same seed always produces the same regions', function (PipsDifficulty $difficulty) {
    $grower = new RegionGrower;

    $randomizerA = new Randomizer(new Mt19937(55));
    $shapeA = (new ShapeGrower)->grow($randomizerA, $difficulty->rows(), $difficulty->cols(), $difficulty->cellCount());
    $a = $grower->grow($randomizerA, $shapeA['dimensions']['rows'], $shapeA['dimensions']['cols'], $shapeA['cells']);

    $randomizerB = new Randomizer(new Mt19937(55));
    $shapeB = (new ShapeGrower)->grow($randomizerB, $difficulty->rows(), $difficulty->cols(), $difficulty->cellCount());
    $b = $grower->grow($randomizerB, $shapeB['dimensions']['rows'], $shapeB['dimensions']['cols'], $shapeB['cells']);

    expect($a)->toEqual($b);
})->with('difficulties');
