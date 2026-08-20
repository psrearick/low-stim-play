<?php

use App\Services\Quartiles\PuzzleGenerator;

beforeEach(function () {
    $wordsPath = tempnam(sys_get_temp_dir(), 'quartiles-words-');
    // 6 candidates, all 8-16 letters, so pickArrayKeys(5) always has a
    // real choice to make -- plus a handful of too-short/too-long words
    // that must never be picked.
    file_put_contents($wordsPath, implode("\n", [
        'a', 'cat', 'dog', // too short
        'backpack', 'chocolate', 'waterfall', 'strawberry', 'playground', 'helicopter',
        'electroencephalographic', // too long
    ]));

    $this->wordsPath = $wordsPath;
    $this->generator = new PuzzleGenerator($wordsPath);
});

afterEach(function () {
    unlink($this->wordsPath);
});

test('a puzzle has exactly 20 tiles across 5 groups of 4', function () {
    $puzzle = $this->generator->generate(seed: 1);

    expect($puzzle['tiles'])->toHaveCount(20)
        ->and($puzzle['groups'])->toHaveCount(5);

    foreach ($puzzle['groups'] as $group) {
        expect($group)->toHaveCount(4);
    }
});

test('every fragment is 2-4 letters', function () {
    $puzzle = $this->generator->generate(seed: 1);

    foreach ($puzzle['tiles'] as $tile) {
        expect(mb_strlen($tile['match']))->toBeGreaterThanOrEqual(2)
            ->and(mb_strlen($tile['match']))->toBeLessThanOrEqual(4);
    }
});

test('each group\'s tiles concatenate back into one of the candidate words', function () {
    $candidates = ['backpack', 'chocolate', 'waterfall', 'strawberry', 'playground', 'helicopter'];
    $puzzle = $this->generator->generate(seed: 1);

    foreach ($puzzle['groups'] as $group) {
        $word = implode('', array_map(fn (int $id) => $puzzle['tiles'][$id]['match'], $group));

        expect($candidates)->toContain($word);
    }
});

test('display is the uppercase of match', function () {
    $puzzle = $this->generator->generate(seed: 1);

    foreach ($puzzle['tiles'] as $tile) {
        expect($tile['display'])->toBe(strtoupper($tile['match']));
    }
});

test('tileOrder is a permutation of every tile id', function () {
    $puzzle = $this->generator->generate(seed: 1);

    expect($puzzle['tileOrder'])->toEqualCanonicalizing(array_keys($puzzle['tiles']));
});

test('the same seed always produces the same puzzle', function () {
    $first = $this->generator->generate(seed: 42);
    $second = $this->generator->generate(seed: 42);

    expect($first)->toBe($second);
});

test('different seeds produce different puzzles', function () {
    $first = $this->generator->generate(seed: 1);
    $second = $this->generator->generate(seed: 2);

    expect($first)->not->toBe($second);
});

test('too few candidate words throws', function () {
    $wordsPath = tempnam(sys_get_temp_dir(), 'quartiles-words-');
    file_put_contents($wordsPath, implode("\n", ['backpack', 'chocolate']));

    $generator = new PuzzleGenerator($wordsPath);

    try {
        expect(fn () => $generator->generate(seed: 1))->toThrow(InvalidArgumentException::class);
    } finally {
        unlink($wordsPath);
    }
});
