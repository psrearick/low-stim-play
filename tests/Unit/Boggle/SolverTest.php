<?php

use App\Services\Boggle\Solver;

beforeEach(function () {
    $wordsPath = tempnam(sys_get_temp_dir(), 'boggle-words-');
    file_put_contents($wordsPath, implode("\n", ['at', 'ca', 'cat', 'cats', 'quit', 'quits']));

    $this->wordsPath = $wordsPath;
    $this->solver = new Solver($wordsPath);

    // 2x2 board:
    //   C A
    //   T S
    // every cell is adjacent to every other cell (it's a 2x2 grid), so
    // "cat" (0-1-2) and "cats" (0-1-2-3) are both reachable paths.
    $this->catsBoard = [
        'cells' => [
            ['match' => 'c'],
            ['match' => 'a'],
            ['match' => 't'],
            ['match' => 's'],
        ],
        'neighbors' => [
            [1, 2, 3],
            [0, 2, 3],
            [0, 1, 3],
            [0, 1, 2],
        ],
    ];
});

afterEach(function () {
    unlink($this->wordsPath);
});

test('it finds known words reachable on the board', function () {
    $found = $this->solver->solve($this->catsBoard);

    expect($found)->toBe(['cat', 'cats']);
});

test('it excludes words shorter than the minimum length even if reachable and in the dictionary', function () {
    // "at" (A->T, index 1 -> index 2) and "ca" (C->A, index 0 -> index 1)
    // are both reachable 2-letter paths and both in the dictionary, but
    // too short to count.
    $found = $this->solver->solve($this->catsBoard);

    expect($found)->not->toContain('at')
        ->and($found)->not->toContain('ca');
});

test('it does not find words that are not reachable via adjacent cells', function () {
    // "quit" isn't spellable from the C/A/T/S board at all.
    $found = $this->solver->solve($this->catsBoard);

    expect($found)->not->toContain('quit');
});

test('the Qu die is walked as two letters, q then u', function () {
    // Qu -> i -> t spells "quit"; Qu -> i -> t -> s spells "quits".
    $board = [
        'cells' => [
            ['match' => 'qu'],
            ['match' => 'i'],
            ['match' => 't'],
            ['match' => 's'],
        ],
        'neighbors' => [
            [1],
            [0, 2],
            [1, 3],
            [2],
        ],
    ];

    expect($this->solver->solve($board))->toBe(['quit', 'quits']);
});

test('a word cannot reuse the same die twice in one path', function () {
    // A single "cat" die/cell adjacent to itself would be nonsensical, but
    // this guards the DFS's visited-backtracking: given only one "t", a
    // path can't double back through it to spell "tat" or similar.
    $board = [
        'cells' => [
            ['match' => 't'],
            ['match' => 'a'],
        ],
        'neighbors' => [
            [1],
            [0],
        ],
    ];

    expect($this->solver->solve($board))->toBe([]);
});

test('isValidWord checks the dictionary directly, independent of any board', function () {
    expect($this->solver->isValidWord('cat'))->toBeTrue()
        ->and($this->solver->isValidWord('CAT'))->toBeTrue()
        ->and($this->solver->isValidWord('quit'))->toBeTrue()
        ->and($this->solver->isValidWord('dinosaur'))->toBeFalse()
        ->and($this->solver->isValidWord('qui'))->toBeFalse();
});
