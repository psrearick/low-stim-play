<?php

use App\Services\Quartiles\Scorer;

beforeEach(function () {
    $this->scorer = new Scorer;
});

test('points match the scoring table for 1-4 tiles', function () {
    expect($this->scorer->pointsFor(1))->toBe(1)
        ->and($this->scorer->pointsFor(2))->toBe(2)
        ->and($this->scorer->pointsFor(3))->toBe(4)
        ->and($this->scorer->pointsFor(4))->toBe(8);
});

test('the quartile bonus is 40', function () {
    expect(Scorer::QUARTILE_BONUS)->toBe(40);
});
