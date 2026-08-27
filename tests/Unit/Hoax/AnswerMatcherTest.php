<?php

use App\Services\Hoax\AnswerMatcher;

beforeEach(function () {
    $this->matcher = new AnswerMatcher;
});

test('an exact match is caught', function () {
    expect($this->matcher->matches('Nebraska', 'Nebraska'))->toBeTrue();
});

test('case is ignored', function () {
    expect($this->matcher->matches('NEBRASKA', 'Nebraska'))->toBeTrue()
        ->and($this->matcher->matches('nebraska', 'Nebraska'))->toBeTrue();
});

test('surrounding whitespace and internal double spaces are ignored', function () {
    expect($this->matcher->matches('  Nebraska  ', 'Nebraska'))->toBeTrue()
        ->and($this->matcher->matches('New   York', 'New York'))->toBeTrue();
});

test('trailing punctuation is ignored', function () {
    expect($this->matcher->matches('Nebraska.', 'Nebraska'))->toBeTrue()
        ->and($this->matcher->matches('Nebraska!', 'Nebraska'))->toBeTrue();
});

test('a leading article is ignored', function () {
    expect($this->matcher->matches('the sun', 'Sun'))->toBeTrue()
        ->and($this->matcher->matches('a business', 'business'))->toBeTrue()
        ->and($this->matcher->matches('an elephant', 'elephant'))->toBeTrue();
});

test('thousands-separator commas are ignored', function () {
    expect($this->matcher->matches('1,200', '1,200'))->toBeTrue()
        ->and($this->matcher->matches('1200', '1,200'))->toBeTrue()
        ->and($this->matcher->matches('1,200', '1200'))->toBeTrue();
});

test('a known alternate spelling is caught even when it differs from the primary answer', function () {
    expect($this->matcher->matches('rube', 'hick', ['rube', 'bumpkin']))->toBeTrue()
        ->and($this->matcher->matches('BUMPKIN', 'hick', ['rube', 'bumpkin']))->toBeTrue();
});

test('a genuinely different fake answer is not flagged', function () {
    expect($this->matcher->matches('a live tarantula', 'Nebraska'))->toBeFalse();
});

test('a similar-looking but different word is not flagged -- this is exact matching, not fuzzy', function () {
    expect($this->matcher->matches('Nebrasca', 'Nebraska'))->toBeFalse()
        ->and($this->matcher->matches('Nebraskan', 'Nebraska'))->toBeFalse();
});

test('an empty or whitespace-only submission never matches', function () {
    expect($this->matcher->matches('', 'Nebraska'))->toBeFalse()
        ->and($this->matcher->matches('   ', 'Nebraska'))->toBeFalse();
});

test('no alternate answers list still works fine, matching only the primary answer', function () {
    expect($this->matcher->matches('Nebraska', 'Nebraska', []))->toBeTrue()
        ->and($this->matcher->matches('Kansas', 'Nebraska', []))->toBeFalse();
});
