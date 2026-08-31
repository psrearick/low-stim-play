<?php

use App\Enums\PipsDifficulty;
use App\Services\Pips\BoardFiller;
use App\Services\Pips\ConstraintChecker;
use App\Services\Pips\ConstraintDeriver;
use App\Services\Pips\ConstraintStatus;
use App\Services\Pips\PuzzleGenerator;
use App\Services\Pips\RegionGrower;
use App\Services\Pips\SolutionSolver;

dataset('difficulties', fn () => PipsDifficulty::cases());

function realPipsGenerator(): PuzzleGenerator
{
    return new PuzzleGenerator(new BoardFiller, new RegionGrower, new ConstraintDeriver, new SolutionSolver(new ConstraintChecker));
}

test('a generated puzzle satisfies every one of its own regions, at every difficulty', function (PipsDifficulty $difficulty) {
    $checker = new ConstraintChecker;
    $puzzle = realPipsGenerator()->generate($difficulty, seed: 123);

    expect($puzzle['board']['dimensions'])->toBe(['rows' => $difficulty->rows(), 'cols' => $difficulty->cols()]);

    $cellValues = $puzzle['solution']['cellValues'];

    foreach ($puzzle['board']['regions'] as $region) {
        $values = array_map(fn (int $cell) => $cellValues[$cell], $region['cells']);
        $status = $checker->check($region['constraint'], $values, 0, $difficulty->maxPip());

        expect($status)->toBe(ConstraintStatus::Satisfied);
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

    $generator = new PuzzleGenerator(new BoardFiller, new RegionGrower, new ConstraintDeriver, $solver);

    $puzzle = $generator->generate(PipsDifficulty::Easy, seed: 7);

    expect($puzzle['board']['regions'])->not->toBeEmpty();
    $solver->shouldHaveReceived('hasObviousDuplicate')->times(3);
});

test('generation still succeeds, accepting the last attempt, once the retry budget is exhausted', function () {
    $solver = Mockery::mock(SolutionSolver::class);
    $solver->shouldReceive('hasObviousDuplicate')->andReturn(true);

    $generator = new PuzzleGenerator(new BoardFiller, new RegionGrower, new ConstraintDeriver, $solver);

    $puzzle = $generator->generate(PipsDifficulty::Easy, seed: 7);

    expect($puzzle['board']['regions'])->not->toBeEmpty();
});
