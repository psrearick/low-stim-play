<?php

use App\Enums\PipsDifficulty;
use App\Services\Pips\Grid;
use App\Services\Pips\RegionGrower;
use Random\Engine\Mt19937;
use Random\Randomizer;

dataset('difficulties', fn () => PipsDifficulty::cases());

test('every cell is covered by exactly one region', function (PipsDifficulty $difficulty) {
    $regions = (new RegionGrower)->grow(new Randomizer(new Mt19937(1)), $difficulty->rows(), $difficulty->cols());

    $seen = [];
    foreach ($regions as $cells) {
        foreach ($cells as $cell) {
            expect($seen)->not->toHaveKey((string) $cell);
            $seen[$cell] = true;
        }
    }

    expect($seen)->toHaveCount($difficulty->cellCount());
    expect(array_keys($seen))->toEqualCanonicalizing(range(0, $difficulty->cellCount() - 1));
})->with('difficulties');

test('every region is a single contiguous blob', function (PipsDifficulty $difficulty) {
    $regions = (new RegionGrower)->grow(new Randomizer(new Mt19937(2)), $difficulty->rows(), $difficulty->cols());
    $neighbors = Grid::neighbors($difficulty->rows(), $difficulty->cols());

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

    $a = $grower->grow(new Randomizer(new Mt19937(55)), $difficulty->rows(), $difficulty->cols());
    $b = $grower->grow(new Randomizer(new Mt19937(55)), $difficulty->rows(), $difficulty->cols());

    expect($a)->toEqual($b);
})->with('difficulties');
