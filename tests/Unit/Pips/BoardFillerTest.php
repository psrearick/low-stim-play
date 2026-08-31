<?php

use App\Enums\PipsDifficulty;
use App\Services\Pips\BoardFiller;
use App\Services\Pips\ShapeGrower;
use Random\Engine\Mt19937;
use Random\Randomizer;

dataset('difficulties', fn () => PipsDifficulty::cases());

/**
 * BoardFiller no longer tiles a fixed dense rectangle -- it fills whatever
 * irregular shape ShapeGrower hands it (docs/DEVLOG.md's Pips entry), so
 * every test here grows a real shape first, exactly like PuzzleGenerator's
 * own orchestration does, rather than assuming a rows()/cols() rectangle is
 * itself the board.
 *
 * @return array{shape: array{cells: list<int>, dimensions: array{rows: int, cols: int}}, result: array{cellValues: array<int, int>, placements: list<array{cellA: int, cellB: int, valueA: int, valueB: int}>}}
 */
function growShapeAndFill(PipsDifficulty $difficulty, int $seed): array
{
    $randomizer = new Randomizer(new Mt19937($seed));
    $shapeGrower = new ShapeGrower;
    $filler = new BoardFiller;

    for ($attempt = 0; $attempt < 30; $attempt++) {
        $shape = $shapeGrower->grow($randomizer, $difficulty->rows(), $difficulty->cols(), $difficulty->cellCount());

        try {
            $result = $filler->fill($randomizer, $shape['dimensions']['rows'], $shape['dimensions']['cols'], $shape['cells'], $difficulty->maxPip());

            return ['shape' => $shape, 'result' => $result];
        } catch (RuntimeException) {
            continue;
        }
    }

    throw new RuntimeException('Could not grow and fill a test shape.');
}

test('fills every cell exactly once', function (PipsDifficulty $difficulty) {
    ['shape' => $shape, 'result' => $result] = growShapeAndFill($difficulty, 1);

    expect($result['cellValues'])->toHaveCount($difficulty->cellCount());

    $coveredCells = array_keys($result['cellValues']);
    sort($coveredCells);
    expect($coveredCells)->toEqual($shape['cells']);

    foreach ($result['cellValues'] as $value) {
        expect($value)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual($difficulty->maxPip());
    }
})->with('difficulties');

test('uses every domino from the full set exactly once', function (PipsDifficulty $difficulty) {
    ['result' => $result] = growShapeAndFill($difficulty, 2);

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
    ['shape' => $shape, 'result' => $result] = growShapeAndFill($difficulty, 3);
    $cols = $shape['dimensions']['cols'];

    foreach ($result['placements'] as $placement) {
        $rowA = intdiv($placement['cellA'], $cols);
        $colA = $placement['cellA'] % $cols;
        $rowB = intdiv($placement['cellB'], $cols);
        $colB = $placement['cellB'] % $cols;

        expect(abs($rowA - $rowB) + abs($colA - $colB))->toBe(1);
    }
})->with('difficulties');

test('the same seed always produces the same fill', function (PipsDifficulty $difficulty) {
    $a = growShapeAndFill($difficulty, 99)['result'];
    $b = growShapeAndFill($difficulty, 99)['result'];

    expect($a)->toEqual($b);
})->with('difficulties');
