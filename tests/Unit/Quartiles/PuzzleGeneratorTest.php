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

test('no two tiles ever share the same text', function () {
    // Real bug report: two tiles showing identical text are
    // indistinguishable to a player, so clicking the visually-right-but-
    // actually-wrong one silently fails to complete a Quad (matched by
    // tile id, not text). Checked against the real dictionary across many
    // seeds, not just the small fixture above, since collisions depend on
    // which real words happen to get picked together.
    // No Laravel helpers (storage_path()) -- this suite doesn't boot the
    // framework (tests/Pest.php only extends TestCase for Feature/Browser).
    $generator = new PuzzleGenerator(__DIR__.'/../../../storage/app/words.txt');

    foreach (range(1, 200) as $seed) {
        $puzzle = $generator->generate($seed);
        $texts = array_map(fn (array $tile) => $tile['match'], $puzzle['tiles']);

        expect($texts)->toHaveCount(count(array_unique($texts)));
    }
});

test('a within-word collision is retried until a collision-free puzzle is found', function () {
    // Each candidate is 8 letters of a single repeated character -- the
    // *only* possible 4-fragment split of an 8-letter word is 2+2+2+2
    // (the 2-letter floor already sums to 8, so splitWord() can't grow
    // any fragment further), which guarantees every one of these words
    // collides with *itself* (four identical 2-letter fragments) on
    // every single attempt, deterministically -- not a probabilistic
    // "might retry" case.
    $wordsPath = tempnam(sys_get_temp_dir(), 'quartiles-words-');
    file_put_contents($wordsPath, implode("\n", [
        str_repeat('a', 8), str_repeat('b', 8), str_repeat('c', 8),
        str_repeat('d', 8), str_repeat('e', 8),
    ]));

    $generator = new PuzzleGenerator($wordsPath);

    try {
        expect(fn () => $generator->generate(seed: 1))->toThrow(RuntimeException::class);
    } finally {
        unlink($wordsPath);
    }
});
