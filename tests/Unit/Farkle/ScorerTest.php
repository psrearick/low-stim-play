<?php

use App\Services\Farkle\Scorer;

beforeEach(function () {
    $this->scorer = new Scorer;
});

test('a single 1 or 5 scores individually', function () {
    expect($this->scorer->scoreSelection([1]))->toBe(['score' => 100, 'valid' => true])
        ->and($this->scorer->scoreSelection([5]))->toBe(['score' => 50, 'valid' => true])
        ->and($this->scorer->scoreSelection([1, 5]))->toBe(['score' => 150, 'valid' => true])
        ->and($this->scorer->scoreSelection([1, 1, 5]))->toBe(['score' => 250, 'valid' => true]);
});

test('a lone die that is not a 1 or 5 is not a valid selection', function () {
    expect($this->scorer->scoreSelection([3]))->toBe(['score' => 0, 'valid' => false])
        ->and($this->scorer->scoreSelection([2, 2]))->toBe(['score' => 0, 'valid' => false]);
});

test('three of a kind scores per the standard table, 1s as 1000', function () {
    expect($this->scorer->scoreSelection([1, 1, 1]))->toBe(['score' => 1000, 'valid' => true])
        ->and($this->scorer->scoreSelection([2, 2, 2]))->toBe(['score' => 200, 'valid' => true])
        ->and($this->scorer->scoreSelection([6, 6, 6]))->toBe(['score' => 600, 'valid' => true]);
});

test('four, five, and six of a kind double the three-of-a-kind value each additional die', function () {
    expect($this->scorer->scoreSelection([4, 4, 4, 4]))->toBe(['score' => 800, 'valid' => true])
        ->and($this->scorer->scoreSelection([4, 4, 4, 4, 4]))->toBe(['score' => 1600, 'valid' => true])
        ->and($this->scorer->scoreSelection([4, 4, 4, 4, 4, 4]))->toBe(['score' => 3200, 'valid' => true])
        ->and($this->scorer->scoreSelection([1, 1, 1, 1]))->toBe(['score' => 2000, 'valid' => true])
        ->and($this->scorer->scoreSelection([1, 1, 1, 1, 1, 1]))->toBe(['score' => 8000, 'valid' => true]);
});

test('an of-a-kind group combined with leftover scoring singles adds both', function () {
    expect($this->scorer->scoreSelection([4, 4, 4, 1, 5]))->toBe(['score' => 550, 'valid' => true]);
});

test('a non-scoring die alongside an otherwise valid group invalidates the whole selection', function () {
    // Three 4s score, but the lone 2 doesn't -- the whole selection is
    // illegal, not just worth less, since Farkle never lets you set aside
    // a die that doesn't contribute.
    expect($this->scorer->scoreSelection([4, 4, 4, 2]))->toBe(['score' => 0, 'valid' => false]);
});

test('an empty selection is invalid', function () {
    expect($this->scorer->scoreSelection([]))->toBe(['score' => 0, 'valid' => false]);
});

test('a roll with at least one 1 or 5 has scoring dice', function () {
    expect($this->scorer->hasAnyScoringDice([2, 3, 4, 6, 1, 6]))->toBeTrue()
        ->and($this->scorer->hasAnyScoringDice([2, 3, 4, 6, 6, 5]))->toBeTrue();
});

test('a roll with three or more of a kind has scoring dice even with no 1s or 5s', function () {
    expect($this->scorer->hasAnyScoringDice([2, 2, 2, 3, 4, 6]))->toBeTrue();
});

test('a roll with no 1s, no 5s, and nothing tripled has no scoring dice', function () {
    expect($this->scorer->hasAnyScoringDice([2, 2, 3, 3, 4, 6]))->toBeFalse();
});
