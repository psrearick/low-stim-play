<?php

use App\Services\SpellingBee\RankCalculator;

beforeEach(function () {
    $this->calculator = new RankCalculator;
});

test('rankFor returns the highest tier whose threshold is met', function () {
    expect($this->calculator->rankFor(0, 100))->toBe('Beginner')
        ->and($this->calculator->rankFor(2, 100))->toBe('Getting Started')
        ->and($this->calculator->rankFor(5, 100))->toBe('Making Progress')
        ->and($this->calculator->rankFor(40, 100))->toBe('Amazing')
        ->and($this->calculator->rankFor(70, 100))->toBe('Genius')
        ->and($this->calculator->rankFor(100, 100))->toBe('Hive Master');
});

test('rankFor treats a maxPossible of 0 as 0%, not a division error', function () {
    expect($this->calculator->rankFor(0, 0))->toBe('Beginner');
});

test('tiers returns every tier name in ascending order, ending with Hive Master', function () {
    $tiers = $this->calculator->tiers();

    expect($tiers[0])->toBe('Beginner')
        ->and(end($tiers))->toBe('Hive Master');
});

test('tierIndex returns the ascending position of a rank, or null for an unknown one', function () {
    $tiers = $this->calculator->tiers();

    expect($this->calculator->tierIndex('Beginner'))->toBe(0)
        ->and($this->calculator->tierIndex('Hive Master'))->toBe(count($tiers) - 1)
        ->and($this->calculator->tierIndex('Not A Rank'))->toBeNull();
});
