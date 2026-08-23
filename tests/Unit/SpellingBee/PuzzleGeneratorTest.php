<?php

use App\Services\SpellingBee\PuzzleGenerator;
use App\Services\Words\CommonWordFilter;

/**
 * Builds $count distinct synthetic "words" (doesn't need to read as
 * English), each anchored on $anchor plus one repeated filler letter drawn
 * round-robin from $fillers -- every word contains $anchor, but any single
 * filler letter only shows up in roughly $count/count($fillers) of them.
 * Used to cheaply clear PuzzleGenerator's MIN_ANSWER_COUNT (20) threshold
 * for exactly one center ($anchor) while keeping every other candidate
 * letter well under it, without hand-writing 20+ real words per fixture.
 *
 * @param  list<string>  $fillers
 * @return list<string>
 */
function syntheticWords(string $anchor, array $fillers, int $count = 25): array
{
    return array_map(
        fn (int $i) => $anchor.str_repeat($fillers[$i % count($fillers)], $i + 3),
        range(0, $count - 1),
    );
}

function commonWordFilter(?string $excludedWordsPath = null): CommonWordFilter
{
    $frequencyPath = tempnam(sys_get_temp_dir(), 'spelling-bee-frequency-');
    file_put_contents($frequencyPath, ''); // unused by excluding(), just needs to exist

    $filter = new CommonWordFilter($frequencyPath, $excludedWordsPath ?? emptyExcludedWordsPath());
    unlink($frequencyPath);

    return $filter;
}

function emptyExcludedWordsPath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'spelling-bee-excluded-');
    file_put_contents($path, '');

    return $path;
}

beforeEach(function () {
    // "trainers" (t,r,a,i,n,e,s -- 7 distinct letters, "r" repeats) is the
    // only pangram candidate; 25 synthetic words all anchored on "t" clear
    // the quality gate for that center specifically, while each of the
    // other 6 letters (used as a filler in only ~4 of the 25) stays well
    // under the 20-word minimum.
    $words = ['trainers', ...syntheticWords('t', ['r', 'a', 'i', 'n', 'e', 's'])];

    $this->wordsPath = tempnam(sys_get_temp_dir(), 'spelling-bee-words-');
    file_put_contents($this->wordsPath, implode("\n", $words));
});

afterEach(function () {
    unlink($this->wordsPath);
});

test('a generated puzzle has 7 distinct letters and a center among them', function () {
    $generator = new PuzzleGenerator(commonWordFilter(), $this->wordsPath);
    $puzzle = $generator->generate(seed: 1);

    expect($puzzle['letters'])->toHaveCount(7)
        ->and($puzzle['letters'])->toBe(array_values(array_unique($puzzle['letters'])))
        ->and($puzzle['letters'])->toContain($puzzle['center']);
});

test('the seed pangram itself is always among the generated words', function () {
    $generator = new PuzzleGenerator(commonWordFilter(), $this->wordsPath);
    $puzzle = $generator->generate(seed: 1);

    expect($puzzle['words'])->toContain('trainers');
});

test('every generated word uses only the puzzle letters and contains the center', function () {
    $generator = new PuzzleGenerator(commonWordFilter(), $this->wordsPath);
    $puzzle = $generator->generate(seed: 1);
    $letterSet = array_flip($puzzle['letters']);

    foreach ($puzzle['words'] as $word) {
        expect(str_contains($word, $puzzle['center']))->toBeTrue();

        foreach (mb_str_split($word) as $char) {
            expect($letterSet)->toHaveKey($char);
        }
    }
});

test('the same seed always produces the same puzzle', function () {
    $generator = new PuzzleGenerator(commonWordFilter(), $this->wordsPath);

    $first = $generator->generate(seed: 42);
    $second = $generator->generate(seed: 42);

    expect($first)->toBe($second);
});

test('a center whose word list is too thin is skipped in favor of one that clears the threshold', function () {
    // Every synthetic word is anchored on "t" -- only "t" as center can
    // ever clear the 20-word minimum here; the other 6 letters each only
    // appear as a filler in ~4 of the 25 synthetic words, well under it.
    $generator = new PuzzleGenerator(commonWordFilter(), $this->wordsPath);

    foreach ([1, 2, 3, 4, 5] as $seed) {
        $puzzle = $generator->generate($seed);
        expect($puzzle['center'])->toBe('t');
    }
});

test('too few qualifying words for every possible center throws', function () {
    // Only 5 "t"-anchored words -- well under the 20-word minimum for
    // every one of the 7 possible centers -- and no other pangram
    // candidate to fall back to.
    $wordsPath = tempnam(sys_get_temp_dir(), 'spelling-bee-words-');
    file_put_contents($wordsPath, implode("\n", [
        'trainers',
        ...syntheticWords('t', ['r', 'a', 'i', 'n', 'e', 's'], count: 5),
    ]));

    $generator = new PuzzleGenerator(commonWordFilter(), $wordsPath);

    try {
        expect(fn () => $generator->generate(seed: 1))->toThrow(RuntimeException::class);
    } finally {
        unlink($wordsPath);
    }
});

test('an excluded word is never chosen as the pangram, even if it would otherwise qualify', function () {
    $excludedPath = tempnam(sys_get_temp_dir(), 'spelling-bee-excluded-');
    file_put_contents($excludedPath, "trainers\n");

    $generator = new PuzzleGenerator(commonWordFilter($excludedPath), $this->wordsPath);

    try {
        // "trainers" is the only pangram candidate in the fixture and it's
        // now excluded -- nothing left to seed a puzzle with.
        expect(fn () => $generator->generate(seed: 1))->toThrow(InvalidArgumentException::class);
    } finally {
        unlink($excludedPath);
    }
});

test('an excluded word never appears in the enumerated answer list', function () {
    // A second, otherwise-qualifying answer word ("tattaa", using only "t"
    // and "a") is on the exclusion list -- it must never surface even
    // though it satisfies the letter/center constraints just like every
    // synthetic word does.
    $words = ['trainers', 'tattaa', ...syntheticWords('t', ['r', 'a', 'i', 'n', 'e', 's'])];
    $wordsPath = tempnam(sys_get_temp_dir(), 'spelling-bee-words-');
    file_put_contents($wordsPath, implode("\n", $words));

    $excludedPath = tempnam(sys_get_temp_dir(), 'spelling-bee-excluded-');
    file_put_contents($excludedPath, "tattaa\n");

    $generator = new PuzzleGenerator(commonWordFilter($excludedPath), $wordsPath);

    try {
        $puzzle = $generator->generate(seed: 1);
        expect($puzzle['words'])->not->toContain('tattaa');
    } finally {
        unlink($wordsPath);
        unlink($excludedPath);
    }
});

test('wordsFor re-derives the same answer list for a known letters/center pair', function () {
    $generator = new PuzzleGenerator(commonWordFilter(), $this->wordsPath);
    $puzzle = $generator->generate(seed: 1);

    $rederived = $generator->wordsFor($puzzle['letters'], $puzzle['center']);

    expect($rederived)->toEqualCanonicalizing($puzzle['words']);
});

test('no pangram candidates at all throws', function () {
    $wordsPath = tempnam(sys_get_temp_dir(), 'spelling-bee-words-');
    file_put_contents($wordsPath, implode("\n", ['cat', 'dog', 'a']));

    $generator = new PuzzleGenerator(commonWordFilter(), $wordsPath);

    try {
        expect(fn () => $generator->generate(seed: 1))->toThrow(InvalidArgumentException::class);
    } finally {
        unlink($wordsPath);
    }
});
