<?php

use App\Enums\YahtzeeCategory;
use App\Services\Yahtzee\GameEngine;
use App\Services\Yahtzee\Scorer;

beforeEach(function () {
    $this->engine = new GameEngine(new Scorer);
});

test('scoring a category with no Yahtzee roll never awards a bonus', function () {
    $result = $this->engine->scoreCategory(YahtzeeCategory::Fours, [4, 4, 4, 4, 2], []);

    expect($result['score'])->toBe(16)
        ->and($result['yahtzeeBonusAwarded'])->toBeFalse();
});

test('scoring the Yahtzee box itself for the first time does not award a bonus', function () {
    $result = $this->engine->scoreCategory(YahtzeeCategory::Yahtzee, [5, 5, 5, 5, 5], [
        'yahtzee' => null,
    ]);

    expect($result['score'])->toBe(50)
        ->and($result['yahtzeeBonusAwarded'])->toBeFalse();
});

test('an extra Yahtzee after the box already shows 50 awards a bonus', function () {
    $result = $this->engine->scoreCategory(YahtzeeCategory::Chance, [3, 3, 3, 3, 3], [
        'yahtzee' => 50,
    ]);

    expect($result['yahtzeeBonusAwarded'])->toBeTrue()
        ->and($result['score'])->toBe(15);
});

test('a forced-zero Yahtzee box does not award a bonus on a later Yahtzee', function () {
    $result = $this->engine->scoreCategory(YahtzeeCategory::Chance, [2, 2, 2, 2, 2], [
        'yahtzee' => 0,
    ]);

    expect($result['yahtzeeBonusAwarded'])->toBeFalse();
});

test('the Joker rule scores Full House/straights at their fixed value once the matching upper box is filled', function () {
    $scores = ['yahtzee' => 50, 'threes' => 9];

    $result = $this->engine->scoreCategory(YahtzeeCategory::FullHouse, [3, 3, 3, 3, 3], $scores);

    expect($result['score'])->toBe(25);
});

test('the Joker rule does not apply if the matching upper box is still open', function () {
    $scores = ['yahtzee' => 50, 'threes' => null];

    $result = $this->engine->scoreCategory(YahtzeeCategory::FullHouse, [3, 3, 3, 3, 3], $scores);

    expect($result['score'])->toBe(0);
});

test('the Joker rule does not apply to non-Yahtzee rolls', function () {
    $scores = ['yahtzee' => 50, 'threes' => 9];

    $result = $this->engine->scoreCategory(YahtzeeCategory::FullHouse, [3, 3, 2, 2, 2], $scores);

    expect($result['score'])->toBe(25); // this is a genuine full house anyway
});

test('upper subtotal sums only the Upper Section categories', function () {
    $scores = ['ones' => 3, 'twos' => 4, 'chance' => 20];

    expect($this->engine->upperSubtotal($scores))->toBe(7);
});

test('upper bonus is awarded at 63 and not below', function () {
    expect($this->engine->upperBonus(['ones' => 3, 'twos' => 6, 'threes' => 9, 'fours' => 12, 'fives' => 15, 'sixes' => 18]))->toBe(35)
        ->and($this->engine->upperBonus(['ones' => 3, 'twos' => 6, 'threes' => 9, 'fours' => 12, 'fives' => 15, 'sixes' => 17]))->toBe(0);
});

test('yahtzee bonus points scale with the bonus count', function () {
    expect($this->engine->yahtzeeBonusPoints(0))->toBe(0)
        ->and($this->engine->yahtzeeBonusPoints(3))->toBe(300);
});

test('total score combines every section', function () {
    $scores = [
        'ones' => 3, 'twos' => 6, 'threes' => 9, 'fours' => 12, 'fives' => 15, 'sixes' => 18, // 63 -> +35 bonus
        'three_of_a_kind' => 20, 'chance' => 25,
    ];

    // 63 + 35 + 20 + 25 + (1 * 100) = 243
    expect($this->engine->totalScore($scores, yahtzeeBonusCount: 1))->toBe(243);
});

test('allCategoriesFilled is false until every category has a score', function () {
    $scores = array_fill_keys(array_map(fn ($c) => $c->value, YahtzeeCategory::all()), 0);

    expect($this->engine->allCategoriesFilled($scores))->toBeTrue();

    $scores['chance'] = null;

    expect($this->engine->allCategoriesFilled($scores))->toBeFalse();
});
