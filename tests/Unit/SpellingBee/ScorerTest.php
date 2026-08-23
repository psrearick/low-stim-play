<?php

use App\Services\SpellingBee\Scorer;

beforeEach(function () {
    $this->scorer = new Scorer;
    $this->letters = ['c', 'r', 'a', 'n', 'e', 's', 't'];
});

test('a 4-letter word scores 1 point', function () {
    expect($this->scorer->pointsFor('cane', $this->letters))->toBe(1);
});

test('each letter beyond four adds one more point', function () {
    expect($this->scorer->pointsFor('crane', $this->letters))->toBe(2)
        ->and($this->scorer->pointsFor('trance', $this->letters))->toBe(3);
});

test('a pangram earns a 7-point bonus on top of its letter-count score', function () {
    // "trances" (t,r,a,n,c,e,s) uses all 7 letters -- length 7 => 4 points,
    // +7 pangram bonus => 11.
    expect($this->scorer->pointsFor('trances', $this->letters))->toBe(11);
});

test('isPangram is true only when every one of the puzzle letters appears', function () {
    expect($this->scorer->isPangram('trances', $this->letters))->toBeTrue()
        ->and($this->scorer->isPangram('crane', $this->letters))->toBeFalse();
});

test('maxPossible sums every word\'s own score', function () {
    $max = $this->scorer->maxPossible(['cane', 'crane'], $this->letters);

    expect($max)->toBe(1 + 2);
});
