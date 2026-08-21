<?php

use App\Enums\GameType;
use App\Services\Puzzles\DailyPuzzle;
use Carbon\CarbonImmutable;

test('the same game and date always produce the same seed', function () {
    $dailyPuzzle = new DailyPuzzle;
    $date = CarbonImmutable::parse('2026-08-21');

    expect($dailyPuzzle->seedFor(GameType::Boggle, $date))
        ->toBe($dailyPuzzle->seedFor(GameType::Boggle, $date));
});

test('a different date produces a different seed', function () {
    $dailyPuzzle = new DailyPuzzle;

    $today = $dailyPuzzle->seedFor(GameType::Boggle, CarbonImmutable::parse('2026-08-21'));
    $tomorrow = $dailyPuzzle->seedFor(GameType::Boggle, CarbonImmutable::parse('2026-08-22'));

    expect($today)->not->toBe($tomorrow);
});

test('a different game type produces a different seed for the same date', function () {
    $dailyPuzzle = new DailyPuzzle;
    $date = CarbonImmutable::parse('2026-08-21');

    $boggleSeed = $dailyPuzzle->seedFor(GameType::Boggle, $date);
    $quartilesSeed = $dailyPuzzle->seedFor(GameType::Quartiles, $date);

    expect($boggleSeed)->not->toBe($quartilesSeed);
});

test('the seed is always a non-negative int', function () {
    $dailyPuzzle = new DailyPuzzle;

    foreach (GameType::cases() as $type) {
        expect($dailyPuzzle->seedFor($type, CarbonImmutable::parse('2026-08-21')))->toBeGreaterThanOrEqual(0);
    }
});
