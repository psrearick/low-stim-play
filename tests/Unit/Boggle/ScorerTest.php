<?php

use App\Services\Boggle\Scorer;

beforeEach(function () {
    $this->scorer = new Scorer;
});

test('points are awarded by word length', function (string $word, int $points) {
    expect($this->scorer->pointsFor($word))->toBe($points);
})->with([
    ['ab', 0],
    ['cat', 1],
    ['cats', 1],
    ['catsy', 2],
    ['kitten', 3],
    ['reptile', 5],
    ['dinosaurs', 11],
    ['counterpoint', 11],
]);

test('total sums points across a list of words', function () {
    expect($this->scorer->totalFor(['cat', 'kitten', 'dinosaurs']))->toBe(1 + 3 + 11);
});

test('an empty word list totals zero', function () {
    expect($this->scorer->totalFor([]))->toBe(0);
});
