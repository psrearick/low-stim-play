<?php

use App\Services\Boggle\Solver;
use App\Services\Quartiles\WordFinder;

beforeEach(function () {
    $wordsPath = tempnam(sys_get_temp_dir(), 'quartiles-words-');
    file_put_contents($wordsPath, implode("\n", ['at', 'cat', 'cats', 'ca']));

    $this->wordsPath = $wordsPath;
    $this->finder = new WordFinder(new Solver($wordsPath));
});

afterEach(function () {
    unlink($this->wordsPath);
});

test('finds a word formed by a single tile', function () {
    $found = $this->finder->findAll([0 => 'at', 1 => 'zz']);

    expect($found)->toHaveKey('at')
        ->and($found['at'])->toBe(1);
});

test('finds a word formed by concatenating two tiles in selection order', function () {
    // "ca" + "t" -> "cat"; the reverse order ("t" + "ca" -> "tca") is not
    // a word, confirming order (not just membership) drives concatenation.
    $found = $this->finder->findAll([0 => 'ca', 1 => 't']);

    expect($found)->toHaveKey('cat')
        ->and($found['cat'])->toBe(2)
        ->and($found)->not->toHaveKey('tca');
});

test('the same word reachable at multiple tile counts records the larger count', function () {
    // "ca"+"t" -> "cat" (2 tiles); "c"+"a"+"t" -> "cat" (3 tiles).
    $found = $this->finder->findAll([0 => 'ca', 1 => 't', 2 => 'c', 3 => 'a']);

    expect($found['cat'])->toBe(3);
});

test('never combines more than 4 tiles, even when a longer word is in the dictionary and enough tiles exist', function () {
    // Both "aaaa" (4 letters) and "aaaaa" (5) are valid dictionary words,
    // and 5 single-"a" tiles are available -- if the depth cap weren't
    // enforced, "aaaaa" would be reachable via all 5 tiles.
    $wordsPath = tempnam(sys_get_temp_dir(), 'quartiles-words-');
    file_put_contents($wordsPath, "aaaa\naaaaa\n");
    $finder = new WordFinder(new Solver($wordsPath));

    $found = $finder->findAll([0 => 'a', 1 => 'a', 2 => 'a', 3 => 'a', 4 => 'a']);

    unlink($wordsPath);

    expect($found)->toHaveKey('aaaa')
        ->and($found['aaaa'])->toBe(4)
        ->and($found)->not->toHaveKey('aaaaa');
});

test('a tile is never reused within one candidate word', function () {
    // "aa" is a valid dictionary word, but only one "a" tile exists -- if
    // a tile could be selected twice, it would be reachable anyway.
    $wordsPath = tempnam(sys_get_temp_dir(), 'quartiles-words-');
    file_put_contents($wordsPath, "aa\n");
    $finder = new WordFinder(new Solver($wordsPath));

    $found = $finder->findAll([0 => 'a']);

    unlink($wordsPath);

    expect($found)->toBe([]);
});

test('words not in the dictionary are never returned', function () {
    $found = $this->finder->findAll([0 => 'z', 1 => 'z']);

    expect($found)->toBe([]);
});
