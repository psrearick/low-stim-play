<?php

use App\Enums\YahtzeeCategory;
use App\Services\Yahtzee\Scorer;

beforeEach(function () {
    $this->scorer = new Scorer;
});

test('upper section categories score the sum of matching dice', function () {
    expect($this->scorer->score(YahtzeeCategory::Fours, [4, 4, 4, 2, 1]))->toBe(12)
        ->and($this->scorer->score(YahtzeeCategory::Sixes, [1, 2, 3, 4, 5]))->toBe(0)
        ->and($this->scorer->score(YahtzeeCategory::Ones, [1, 1, 1, 1, 1]))->toBe(5);
});

test('three of a kind sums all dice when at least 3 match, otherwise 0', function () {
    expect($this->scorer->score(YahtzeeCategory::ThreeOfAKind, [3, 3, 3, 5, 6]))->toBe(20)
        ->and($this->scorer->score(YahtzeeCategory::ThreeOfAKind, [1, 2, 3, 4, 5]))->toBe(0);
});

test('four of a kind sums all dice when at least 4 match, otherwise 0', function () {
    expect($this->scorer->score(YahtzeeCategory::FourOfAKind, [5, 5, 5, 5, 2]))->toBe(22)
        ->and($this->scorer->score(YahtzeeCategory::FourOfAKind, [5, 5, 5, 2, 2]))->toBe(0);
});

test('full house scores 25 for a three-two split, otherwise 0', function () {
    expect($this->scorer->score(YahtzeeCategory::FullHouse, [2, 2, 2, 5, 5]))->toBe(25)
        ->and($this->scorer->score(YahtzeeCategory::FullHouse, [2, 2, 3, 5, 5]))->toBe(0)
        // Five of a kind is not a full house.
        ->and($this->scorer->score(YahtzeeCategory::FullHouse, [2, 2, 2, 2, 2]))->toBe(0);
});

test('small straight scores 30 for any 4 sequential values, otherwise 0', function () {
    expect($this->scorer->score(YahtzeeCategory::SmallStraight, [1, 2, 3, 4, 6]))->toBe(30)
        // A duplicate alongside the run still counts.
        ->and($this->scorer->score(YahtzeeCategory::SmallStraight, [2, 2, 3, 4, 5]))->toBe(30)
        ->and($this->scorer->score(YahtzeeCategory::SmallStraight, [1, 1, 2, 4, 6]))->toBe(0);
});

test('large straight scores 40 for exactly 5 sequential values, otherwise 0', function () {
    expect($this->scorer->score(YahtzeeCategory::LargeStraight, [1, 2, 3, 4, 5]))->toBe(40)
        ->and($this->scorer->score(YahtzeeCategory::LargeStraight, [2, 3, 4, 5, 6]))->toBe(40)
        ->and($this->scorer->score(YahtzeeCategory::LargeStraight, [1, 2, 3, 4, 4]))->toBe(0);
});

test('yahtzee scores 50 for five of a kind, otherwise 0', function () {
    expect($this->scorer->score(YahtzeeCategory::Yahtzee, [4, 4, 4, 4, 4]))->toBe(50)
        ->and($this->scorer->score(YahtzeeCategory::Yahtzee, [4, 4, 4, 4, 5]))->toBe(0);
});

test('chance always sums every die', function () {
    expect($this->scorer->score(YahtzeeCategory::Chance, [1, 2, 3, 4, 5]))->toBe(15);
});

test('scoring with fewer than 5 dice always yields 0', function () {
    expect($this->scorer->score(YahtzeeCategory::Chance, [5, 5, 5]))->toBe(0);
});

test('the joker bonus, when eligible, overrides full house/straight rolls to their fixed value', function () {
    // Five of a kind would normally score 0 for these categories.
    expect($this->scorer->score(YahtzeeCategory::FullHouse, [3, 3, 3, 3, 3], jokerBonusEligible: true))->toBe(25)
        ->and($this->scorer->score(YahtzeeCategory::SmallStraight, [3, 3, 3, 3, 3], jokerBonusEligible: true))->toBe(30)
        ->and($this->scorer->score(YahtzeeCategory::LargeStraight, [3, 3, 3, 3, 3], jokerBonusEligible: true))->toBe(40);
});

test('isYahtzeeRoll detects five of a kind', function () {
    expect($this->scorer->isYahtzeeRoll([2, 2, 2, 2, 2]))->toBeTrue()
        ->and($this->scorer->isYahtzeeRoll([2, 2, 2, 2, 3]))->toBeFalse();
});

test('matchingUpperCategory maps a roll to its Upper Section box', function () {
    expect($this->scorer->matchingUpperCategory([4, 4, 4, 4, 4]))->toBe(YahtzeeCategory::Fours);
});
