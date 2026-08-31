<?php

use App\Enums\PipsDifficulty;
use App\Services\Pips\Grid;
use App\Services\Pips\RegionColorer;
use App\Services\Pips\RegionGrower;
use App\Services\Pips\ShapeGrower;
use Random\Engine\Mt19937;
use Random\Randomizer;

dataset('difficulties', fn () => PipsDifficulty::cases());

/**
 * @return array{regions: array<int, list<int>>, neighbors: array<int, list<int>>}
 */
function realPipsRegions(PipsDifficulty $difficulty, int $seed): array
{
    $randomizer = new Randomizer(new Mt19937($seed));
    $shape = (new ShapeGrower)->grow($randomizer, $difficulty->rows(), $difficulty->cols(), $difficulty->cellCount());
    $regions = (new RegionGrower)->grow($randomizer, $shape['dimensions']['rows'], $shape['dimensions']['cols'], $shape['cells']);
    $neighbors = Grid::activeNeighbors($shape['dimensions']['rows'], $shape['dimensions']['cols'], $shape['cells']);

    return ['regions' => $regions, 'neighbors' => $neighbors];
}

test('no two orthogonally-adjacent regions share a color', function (PipsDifficulty $difficulty) {
    ['regions' => $regions, 'neighbors' => $neighbors] = realPipsRegions($difficulty, 10);

    $regionOf = [];
    foreach ($regions as $regionId => $cells) {
        foreach ($cells as $cell) {
            $regionOf[$cell] = $regionId;
        }
    }

    $colors = (new RegionColorer)->assign($regions, $neighbors, new Randomizer(new Mt19937(10)));

    foreach ($regions as $regionId => $cells) {
        foreach ($cells as $cell) {
            foreach ($neighbors[$cell] as $neighbor) {
                $neighborRegion = $regionOf[$neighbor];

                if ($neighborRegion !== $regionId) {
                    expect($colors[$neighborRegion])->not->toBe($colors[$regionId]);
                }
            }
        }
    }
})->with('difficulties');

test('every region gets a color within the palette size', function (PipsDifficulty $difficulty) {
    ['regions' => $regions, 'neighbors' => $neighbors] = realPipsRegions($difficulty, 11);

    $colors = (new RegionColorer)->assign($regions, $neighbors, new Randomizer(new Mt19937(11)));

    expect($colors)->toHaveCount(count($regions));

    foreach ($colors as $color) {
        expect($color)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(RegionColorer::PALETTE_SIZE - 1);
    }
})->with('difficulties');

test('the same seed always produces the same colors', function (PipsDifficulty $difficulty) {
    ['regions' => $regions, 'neighbors' => $neighbors] = realPipsRegions($difficulty, 12);
    $colorer = new RegionColorer;

    $a = $colorer->assign($regions, $neighbors, new Randomizer(new Mt19937(99)));
    $b = $colorer->assign($regions, $neighbors, new Randomizer(new Mt19937(99)));

    expect($a)->toEqual($b);
})->with('difficulties');
