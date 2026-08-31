<?php

use App\Services\Pips\ConstraintChecker;
use App\Services\Pips\SolutionSolver;
use App\Services\Pips\SolverStatus;

beforeEach(function () {
    $this->solver = new SolutionSolver(new ConstraintChecker);
});

/**
 * A 1x4 path graph has exactly one perfect matching (node 0's only
 * neighbor is node 1, forcing that pair; nodes 2/3 must then pair too) --
 * removing all tiling freedom lets this isolate whether singleton "sum"
 * constraints (each fixing one cell's exact value) actually pin down a
 * unique board.
 */
test('a fully pinned-down board is reported unique', function () {
    $regions = [
        0 => ['cells' => [0], 'constraint' => ['type' => 'sum', 'value' => 0]],
        1 => ['cells' => [1], 'constraint' => ['type' => 'sum', 'value' => 1]],
        2 => ['cells' => [2], 'constraint' => ['type' => 'sum', 'value' => 1]],
        3 => ['cells' => [3], 'constraint' => ['type' => 'sum', 'value' => 1]],
    ];
    $knownSolution = [
        'cellValues' => [0 => 0, 1 => 1, 2 => 1, 3 => 1],
        'placements' => [
            ['cellA' => 0, 'cellB' => 1, 'valueA' => 0, 'valueB' => 1],
            ['cellA' => 2, 'cellB' => 3, 'valueA' => 1, 'valueB' => 1],
        ],
    ];

    expect($this->solver->check($regions, 1, 4, 1, $knownSolution))->toBe(SolverStatus::Unique);
    expect($this->solver->hasObviousDuplicate($regions, 1, $knownSolution))->toBeFalse();
});

test('a board with no real constraints admits a second solution', function () {
    $regions = [0 => ['cells' => [0, 1, 2, 3], 'constraint' => ['type' => 'none', 'value' => null]]];
    $knownSolution = [
        'cellValues' => [0 => 0, 1 => 0, 2 => 1, 3 => 1],
        'placements' => [
            ['cellA' => 0, 'cellB' => 1, 'valueA' => 0, 'valueB' => 0],
            ['cellA' => 2, 'cellB' => 3, 'valueA' => 1, 'valueB' => 1],
        ],
    ];

    // The full exhaustive search finds it (a genuinely different tiling --
    // e.g. pairing (0,2)/(1,3) vertically instead) -- but the fast
    // hasObviousDuplicate() pre-check only tries swapping which *already-
    // placed* domino sits at which existing pair, and both dominoes here
    // are fully inside the one 'none' region, so swapping them changes
    // nothing that region can observe (see this class's own docblock) and
    // correctly doesn't register as a duplicate through that cheap path.
    expect($this->solver->check($regions, 1, 4, 1, $knownSolution))->toBe(SolverStatus::NotUnique);
    expect($this->solver->hasObviousDuplicate($regions, 1, $knownSolution))->toBeFalse();
});

test('two dominoes fully inside one region are not a duplicate merely by swapping', function () {
    // Sum stays identical either way -- every constraint type only cares
    // about a region's value multiset, never which cell holds which value
    // (see SolutionSolver's own docblock).
    $regions = [0 => ['cells' => [0, 1, 2, 3], 'constraint' => ['type' => 'sum', 'value' => 6]]];
    $knownSolution = [
        'cellValues' => [0 => 1, 1 => 2, 2 => 1, 3 => 2],
        'placements' => [
            ['cellA' => 0, 'cellB' => 1, 'valueA' => 1, 'valueB' => 2],
            ['cellA' => 2, 'cellB' => 3, 'valueA' => 1, 'valueB' => 2],
        ],
    ];

    expect($this->solver->hasObviousDuplicate($regions, 2, $knownSolution))->toBeFalse();
});

test('two dominoes in separate none regions are a genuine duplicate', function () {
    $regions = [
        0 => ['cells' => [0, 1], 'constraint' => ['type' => 'none', 'value' => null]],
        1 => ['cells' => [2, 3], 'constraint' => ['type' => 'none', 'value' => null]],
    ];
    $knownSolution = [
        'cellValues' => [0 => 0, 1 => 0, 2 => 1, 3 => 1],
        'placements' => [
            ['cellA' => 0, 'cellB' => 1, 'valueA' => 0, 'valueB' => 0],
            ['cellA' => 2, 'cellB' => 3, 'valueA' => 1, 'valueB' => 1],
        ],
    ];

    expect($this->solver->hasObviousDuplicate($regions, 1, $knownSolution))->toBeTrue();
});
