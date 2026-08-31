<?php

use App\Services\Pips\Scorer;

test('a faster solve scores higher than a slower one', function () {
    $scorer = new Scorer;

    expect($scorer->scoreFor(10))->toBeGreaterThan($scorer->scoreFor(100));
});

test('an instant solve scores the max', function () {
    expect((new Scorer)->scoreFor(0))->toBe(1000);
});

test('score never drops below zero for a very long solve', function () {
    expect((new Scorer)->scoreFor(100000))->toBe(0);
});
