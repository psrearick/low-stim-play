<?php

use App\Services\Boggle\BoardGenerator;

beforeEach(function () {
    $this->generator = new BoardGenerator;
});

test('a 4x4 board has 16 cells and a 5x5 board has 25', function () {
    expect($this->generator->generate(4, seed: 1)['cells'])->toHaveCount(16)
        ->and($this->generator->generate(5, seed: 1)['cells'])->toHaveCount(25);
});

test('only 4 and 5 are valid board sizes', function () {
    $this->generator->generate(6);
})->throws(InvalidArgumentException::class);

test('the same seed always produces the same board', function () {
    $first = $this->generator->generate(4, seed: 42);
    $second = $this->generator->generate(4, seed: 42);

    expect($first)->toBe($second);
});

test('different seeds produce different boards', function () {
    $first = $this->generator->generate(4, seed: 1);
    $second = $this->generator->generate(4, seed: 2);

    expect($first['cells'])->not->toBe($second['cells']);
});

test('each cell has a lowercase match derived from its display face', function () {
    $board = $this->generator->generate(4, seed: 1);

    foreach ($board['cells'] as $cell) {
        expect($cell['match'])->toBe(strtolower($cell['display']));
    }
});

test('the Qu die face is preserved as a two-letter match', function () {
    // Seed 7 places the "Qu" die somewhere on a 4x4 board; if this ever
    // breaks because of an unrelated change to the shuffle, try nearby
    // seeds -- the point is that *some* board contains it intact.
    $seeds = range(1, 50);
    $foundQu = false;

    foreach ($seeds as $seed) {
        $board = $this->generator->generate(4, seed: $seed);

        foreach ($board['cells'] as $cell) {
            if ($cell['display'] === 'Qu') {
                $foundQu = true;
                expect($cell['match'])->toBe('qu');
            }
        }
    }

    expect($foundQu)->toBeTrue();
});

test('adjacency is symmetric and only includes in-bounds orthogonal and diagonal neighbors', function () {
    $board = $this->generator->generate(4, seed: 1);
    $neighbors = $board['neighbors'];

    expect($neighbors)->toHaveCount(16);

    // Corner cell (index 0, row 0 col 0) has exactly 3 neighbors.
    expect($neighbors[0])->toHaveCount(3)
        ->and($neighbors[0])->toEqualCanonicalizing([1, 4, 5]);

    // Center-ish cell (index 5, row 1 col 1) has all 8 neighbors.
    expect($neighbors[5])->toHaveCount(8);

    foreach ($neighbors as $index => $adjacent) {
        foreach ($adjacent as $neighborIndex) {
            expect($neighbors[$neighborIndex])->toContain($index);
        }
    }
});
