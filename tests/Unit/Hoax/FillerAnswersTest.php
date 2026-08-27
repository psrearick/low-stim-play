<?php

use App\Services\Hoax\FillerAnswers;

beforeEach(function () {
    $this->filler = new FillerAnswers;
});

test('pick returns a non-empty line for any index', function () {
    expect($this->filler->pick(0))->toBeString()->not->toBeEmpty();
});

test('pick is deterministic -- the same index always returns the same line', function () {
    expect($this->filler->pick(3))->toBe($this->filler->pick(3));
});

test('consecutive indices return different lines', function () {
    expect($this->filler->pick(0))->not->toBe($this->filler->pick(1));
});

test('pick never goes out of bounds for a large index, wrapping instead', function () {
    expect($this->filler->pick(1000))->toBeString()->not->toBeEmpty();
});
