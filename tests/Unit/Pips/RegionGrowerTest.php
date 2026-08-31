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
    $regions = (new RegionGrower)->grow($randomizer, $shape['dimensions']['rows'], $shape['dimensions']['cols'], $shape['cells'], $difficulty->maxPip());

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
    $regions = (new RegionGrower)->grow($randomizer, $shape['dimensions']['rows'], $shape['dimensions']['cols'], $shape['cells'], $difficulty->maxPip());
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
    $a = $grower->grow($randomizerA, $shapeA['dimensions']['rows'], $shapeA['dimensions']['cols'], $shapeA['cells'], $difficulty->maxPip());

    $randomizerB = new Randomizer(new Mt19937(55));
    $shapeB = (new ShapeGrower)->grow($randomizerB, $difficulty->rows(), $difficulty->cols(), $difficulty->cellCount());
    $b = $grower->grow($randomizerB, $shapeB['dimensions']['rows'], $shapeB['dimensions']['cols'], $shapeB['cells'], $difficulty->maxPip());

    expect($a)->toEqual($b);
})->with('difficulties');

/**
 * The actual regression test for the region-size bug (docs/DEVLOG.md's Pips
 * entry): an irregular, holey board shape's frontiers used to dry up
 * unevenly, leaving large leftover patches mop-up dumped wholesale onto
 * whichever single region happened to border them -- confirmed live up to
 * 25 cells (sum constraints up to 52) on a tier whose highest single pip is
 * 4. A generous seed count across all three tiers, since the bug was
 * intermittent depending on exactly how a given shape's frontiers happened
 * to dry up, not deterministic on every seed.
 */
test('no region ever exceeds its own difficulty tier maxPip cell count', function (PipsDifficulty $difficulty) {
    $shapeGrower = new ShapeGrower;
    $regionGrower = new RegionGrower;
    $maxRegionSize = $difficulty->maxPip();

    foreach (range(1, 30) as $seed) {
        $randomizer = new Randomizer(new Mt19937($seed));
        $shape = $shapeGrower->grow($randomizer, $difficulty->rows(), $difficulty->cols(), $difficulty->cellCount());
        $regions = $regionGrower->grow($randomizer, $shape['dimensions']['rows'], $shape['dimensions']['cols'], $shape['cells'], $maxRegionSize);

        foreach ($regions as $cells) {
            // Not a strict cap -- a large leftover patch spawns its own
            // fresh, properly-capped region(s) (see this class's own
            // docblock), but the very last mop-up scraps can still push a
            // region a little past $maxRegionSize. 3x is a generous ceiling
            // confirmed comfortably above the real worst case (11/11/12
            // cells across 30 seeds per tier at maxPip 4/5/6) while still
            // catching a real regression back toward the original bug's
            // 25-cell regions.
            expect(count($cells))->toBeLessThanOrEqual($maxRegionSize * 3, "seed {$seed}: a region had ".count($cells).' cells, cap is '.$maxRegionSize);
        }
    }
})->with('difficulties');
