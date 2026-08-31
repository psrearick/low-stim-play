<?php

use App\Services\Pips\ConstraintChecker;
use App\Services\Pips\ConstraintStatus;

beforeEach(function () {
    $this->checker = new ConstraintChecker;
});

test('sum: unknown while under target with room to reach it, satisfied exactly on target, broken once exceeded or unreachable', function () {
    expect($this->checker->check(['type' => 'sum', 'value' => 10], [3, 3], 1, 6))->toBe(ConstraintStatus::Unknown)
        ->and($this->checker->check(['type' => 'sum', 'value' => 10], [3, 3, 4], 0, 6))->toBe(ConstraintStatus::Satisfied)
        ->and($this->checker->check(['type' => 'sum', 'value' => 10], [3, 3, 5], 0, 6))->toBe(ConstraintStatus::Broken)
        ->and($this->checker->check(['type' => 'sum', 'value' => 2], [3], 1, 6))->toBe(ConstraintStatus::Broken)
        ->and($this->checker->check(['type' => 'sum', 'value' => 20], [1], 1, 6))->toBe(ConstraintStatus::Broken);
});

test('equal: broken the moment two filled values differ, satisfied once full and uniform', function () {
    expect($this->checker->check(['type' => 'equal', 'value' => null], [2], 2, 6))->toBe(ConstraintStatus::Unknown)
        ->and($this->checker->check(['type' => 'equal', 'value' => null], [2, 3], 1, 6))->toBe(ConstraintStatus::Broken)
        ->and($this->checker->check(['type' => 'equal', 'value' => null], [2, 2, 2], 0, 6))->toBe(ConstraintStatus::Satisfied);
});

test('different: broken the moment two filled values match, satisfied once full and distinct', function () {
    expect($this->checker->check(['type' => 'different', 'value' => null], [2, 3], 1, 6))->toBe(ConstraintStatus::Unknown)
        ->and($this->checker->check(['type' => 'different', 'value' => null], [2, 2], 1, 6))->toBe(ConstraintStatus::Broken)
        ->and($this->checker->check(['type' => 'different', 'value' => null], [2, 3, 4], 0, 6))->toBe(ConstraintStatus::Satisfied);
});

test('greater: resolves satisfied early once the bar is already cleared, broken once unreachable', function () {
    expect($this->checker->check(['type' => 'greater', 'value' => 5], [6], 1, 6))->toBe(ConstraintStatus::Satisfied)
        ->and($this->checker->check(['type' => 'greater', 'value' => 5], [1], 1, 6))->toBe(ConstraintStatus::Unknown)
        ->and($this->checker->check(['type' => 'greater', 'value' => 5], [1], 0, 6))->toBe(ConstraintStatus::Broken);
});

test('less: resolves satisfied early once the ceiling can no longer be reached, broken once already at/over', function () {
    expect($this->checker->check(['type' => 'less', 'value' => 5], [1], 1, 6))->toBe(ConstraintStatus::Unknown)
        ->and($this->checker->check(['type' => 'less', 'value' => 5], [1], 0, 6))->toBe(ConstraintStatus::Satisfied)
        ->and($this->checker->check(['type' => 'less', 'value' => 5], [5], 0, 6))->toBe(ConstraintStatus::Broken);
});

test('none is always satisfied', function () {
    expect($this->checker->check(['type' => 'none', 'value' => null], [], 3, 6))->toBe(ConstraintStatus::Satisfied);
});
