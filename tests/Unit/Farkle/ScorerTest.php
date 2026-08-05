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
        ->and($this->scorer->scoreSelection([3, 3, 3]))->toBe(['score' => 300, 'valid' => true])
        ->and($this->scorer->scoreSelection([4, 4, 4]))->toBe(['score' => 400, 'valid' => true])
        ->and($this->scorer->scoreSelection([5, 5, 5]))->toBe(['score' => 500, 'valid' => true])
        ->and($this->scorer->scoreSelection([6, 6, 6]))->toBe(['score' => 600, 'valid' => true]);
});

test('four, five, and six of a kind are flat values regardless of face, per docs/concerns.md', function () {
    expect($this->scorer->scoreSelection([2, 2, 2, 2]))->toBe(['score' => 1000, 'valid' => true])
        ->and($this->scorer->scoreSelection([6, 6, 6, 6]))->toBe(['score' => 1000, 'valid' => true])
        ->and($this->scorer->scoreSelection([2, 2, 2, 2, 2]))->toBe(['score' => 2000, 'valid' => true])
        ->and($this->scorer->scoreSelection([2, 2, 2, 2, 2, 2]))->toBe(['score' => 3000, 'valid' => true]);
});

test('four or five of a kind of 1s or 5s scores whichever interpretation is worth more', function () {
    // Four 1s: three 1s (1000) + one leftover single 1 (100) = 1100, which
    // beats the flat four-of-a-kind value of 1000.
    expect($this->scorer->scoreSelection([1, 1, 1, 1]))->toBe(['score' => 1100, 'valid' => true])
        // Five 1s: flat five-of-a-kind (2000) beats three 1s + two
        // leftover singles (1000 + 200 = 1200).
        ->and($this->scorer->scoreSelection([1, 1, 1, 1, 1]))->toBe(['score' => 2000, 'valid' => true])
        // Four 5s: flat four-of-a-kind (1000) beats three 5s + one
        // leftover single 5 (500 + 50 = 550).
        ->and($this->scorer->scoreSelection([5, 5, 5, 5]))->toBe(['score' => 1000, 'valid' => true]);
});

test('a straight (one of each face) scores 1500 flat', function () {
    expect($this->scorer->scoreSelection([1, 2, 3, 4, 5, 6]))->toBe(['score' => 1500, 'valid' => true])
        ->and($this->scorer->scoreSelection([6, 5, 4, 3, 2, 1]))->toBe(['score' => 1500, 'valid' => true]);
});

test('three pairs scores 1500 flat, even with no 1s or 5s involved', function () {
    expect($this->scorer->scoreSelection([2, 2, 3, 3, 4, 4]))->toBe(['score' => 1500, 'valid' => true])
        ->and($this->scorer->scoreSelection([1, 1, 5, 5, 6, 6]))->toBe(['score' => 1500, 'valid' => true]);
});

test('two triplets scores 2500 flat, beating the sum of two separate three-of-a-kinds', function () {
    // Scored separately this would be 200 (three 2s) + 500 (three 5s) =
    // 700 -- the two-triplets bonus (2500) applies instead.
    expect($this->scorer->scoreSelection([2, 2, 2, 5, 5, 5]))->toBe(['score' => 2500, 'valid' => true]);
});

test('six of a kind scores 3000 flat', function () {
    expect($this->scorer->scoreSelection([4, 4, 4, 4, 4, 4]))->toBe(['score' => 3000, 'valid' => true]);
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

test('scoreMaximal auto-includes every scoring die and ignores dead ones, never invalid', function () {
    // A lone 2 alongside three 4s and a 1: the 2 simply doesn't
    // contribute (0), rather than invalidating the total the way
    // scoreSelection would.
    expect($this->scorer->scoreMaximal([4, 4, 4, 2, 1]))->toBe(500)
        ->and($this->scorer->scoreMaximal([2, 2]))->toBe(0)
        ->and($this->scorer->scoreMaximal([]))->toBe(0);
});

test('scoreMaximal picks up the same six-dice special patterns as scoreSelection', function () {
    expect($this->scorer->scoreMaximal([1, 2, 3, 4, 5, 6]))->toBe(1500)
        ->and($this->scorer->scoreMaximal([2, 2, 3, 3, 4, 4]))->toBe(1500)
        ->and($this->scorer->scoreMaximal([2, 2, 2, 5, 5, 5]))->toBe(2500);
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

test('three pairs of non-1/5 faces still counts as having scoring dice', function () {
    // No lone 1s/5s and no 3+-of-a-kind group -- would otherwise look
    // like a bust without the six-dice special-pattern check.
    expect($this->scorer->hasAnyScoringDice([2, 2, 3, 3, 4, 4]))->toBeTrue();
});
