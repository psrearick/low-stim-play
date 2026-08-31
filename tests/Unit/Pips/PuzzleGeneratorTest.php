<?php

use App\Enums\PipsDifficulty;
use App\Services\Pips\BoardFiller;
use App\Services\Pips\ConstraintChecker;
use App\Services\Pips\ConstraintDeriver;
use App\Services\Pips\ConstraintStatus;
use App\Services\Pips\PuzzleGenerator;
use App\Services\Pips\RegionColorer;
use App\Services\Pips\RegionGrower;
use App\Services\Pips\ShapeGrower;
use App\Services\Pips\SolutionSolver;

dataset('difficulties', fn () => PipsDifficulty::cases());

function realPipsGenerator(): PuzzleGenerator
{
    return new PuzzleGenerator(
        new ShapeGrower,
        new BoardFiller,
        new RegionGrower,
        new ConstraintDeriver,
        new RegionColorer,
        new SolutionSolver(new ConstraintChecker),
    );
}

test('a generated puzzle satisfies every one of its own regions, at every difficulty', function (PipsDifficulty $difficulty) {
    $checker = new ConstraintChecker;
    $puzzle = realPipsGenerator()->generate($difficulty, seed: 123);

    // dimensions is the grown shape's own *tight* bounding box, not the
    // tier's own oversized bounding box those growth attempts had room to
    // wander within (App\Enums\PipsDifficulty's own rows()/cols() docblock)
    // -- it can only ever be smaller or equal, never larger.
    expect($puzzle['board']['dimensions']['rows'])->toBeLessThanOrEqual($difficulty->rows());
    expect($puzzle['board']['dimensions']['cols'])->toBeLessThanOrEqual($difficulty->cols());
    expect($puzzle['board']['cells'])->toHaveCount($difficulty->cellCount());

    $cellValues = $puzzle['solution']['cellValues'];

    foreach ($puzzle['board']['regions'] as $region) {
        $values = array_map(fn (int $cell) => $cellValues[$cell], $region['cells']);
        $status = $checker->check($region['constraint'], $values, 0, $difficulty->maxPip());

        expect($status)->toBe(ConstraintStatus::Satisfied);
        expect($region['color'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(RegionColorer::PALETTE_SIZE - 1);
        expect($region['badge'] === null)->toBe($region['constraint']['type'] === 'none');
    }
})->with('difficulties');

/**
 * The end-to-end regression test for the region-size bug (see
 * RegionGrowerTest's own "no region ever exceeds..." test and
 * docs/DEVLOG.md's Pips entry): a 'sum' constraint's own value can never
 * exceed what its region could possibly reach (maxPip per cell), which is
 * trivially true once RegionGrower's own cap holds -- worth asserting
 * directly here too since this is what a player actually sees.
 */
test('every sum constraint stays within its own region\'s max possible total', function (PipsDifficulty $difficulty) {
    $generator = realPipsGenerator();

    foreach (range(1, 20) as $seed) {
        $puzzle = $generator->generate($difficulty, $seed);

        foreach ($puzzle['board']['regions'] as $region) {
            if ($region['constraint']['type'] !== 'sum') {
                continue;
            }

            $maxPossible = $difficulty->maxPip() * count($region['cells']);

            expect($region['constraint']['value'])->toBeLessThanOrEqual(
                $maxPossible,
                "seed {$seed}: a sum constraint of {$region['constraint']['value']} on a ".count($region['cells'])."-cell region exceeds the max possible {$maxPossible}",
            );
        }
    }
})->with('difficulties');

test('the same seed always produces the same puzzle', function () {
    $generator = realPipsGenerator();

    $a = $generator->generate(PipsDifficulty::Easy, seed: 55);
    $b = $generator->generate(PipsDifficulty::Easy, seed: 55);

    expect($a)->toEqual($b);
});

test('a rejected region layout is retried until one is accepted', function () {
    $solver = Mockery::mock(SolutionSolver::class);
    $solver->shouldReceive('hasObviousDuplicate')->andReturn(true, true, false);

    $generator = new PuzzleGenerator(new ShapeGrower, new BoardFiller, new RegionGrower, new ConstraintDeriver, new RegionColorer, $solver);

    $puzzle = $generator->generate(PipsDifficulty::Easy, seed: 7);

    expect($puzzle['board']['regions'])->not->toBeEmpty();
    $solver->shouldHaveReceived('hasObviousDuplicate')->times(3);
});

test('generation still succeeds, accepting the last attempt, once the retry budget is exhausted', function () {
    $solver = Mockery::mock(SolutionSolver::class);
    $solver->shouldReceive('hasObviousDuplicate')->andReturn(true);

    $generator = new PuzzleGenerator(new ShapeGrower, new BoardFiller, new RegionGrower, new ConstraintDeriver, new RegionColorer, $solver);

    $puzzle = $generator->generate(PipsDifficulty::Easy, seed: 7);

    expect($puzzle['board']['regions'])->not->toBeEmpty();
});
