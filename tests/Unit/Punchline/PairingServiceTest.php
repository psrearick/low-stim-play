<?php

use App\Services\Punchline\PairingService;

beforeEach(function () {
    $this->pairing = new PairingService;
});

test('promptCountForRound always returns the player count -- every matchup is a plain pair', function () {
    expect($this->pairing->promptCountForRound(3))->toBe(3)
        ->and($this->pairing->promptCountForRound(4))->toBe(4)
        ->and($this->pairing->promptCountForRound(5))->toBe(5)
        ->and($this->pairing->promptCountForRound(6))->toBe(6)
        ->and($this->pairing->promptCountForRound(7))->toBe(7)
        ->and($this->pairing->promptCountForRound(8))->toBe(8);
});

test('generateRoundMatchups rejects a player count outside 3-8', function () {
    expect(fn () => $this->pairing->generateRoundMatchups(2))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->pairing->generateRoundMatchups(9))->toThrow(InvalidArgumentException::class);
});

test('P=4 matches the hand-verified pairing from docs/specs/punchline.md', function () {
    $matchups = $this->pairing->generateRoundMatchups(4);

    expect($matchups)->toBe([
        [3, 0],
        [1, 2],
        [3, 2],
        [0, 1],
    ]);
});

test('P=3 produces three distinct head-to-head pairs, matching docs/concerns.md\'s example', function () {
    $matchups = $this->pairing->generateRoundMatchups(3);

    expect($matchups)->toBe([
        [0, 1],
        [1, 2],
        [2, 0],
    ]);
});

test('P=5 produces a 5-cycle of distinct pairs, no repeats', function () {
    $matchups = $this->pairing->generateRoundMatchups(5);

    expect($matchups)->toBe([
        [0, 1],
        [1, 2],
        [2, 3],
        [3, 4],
        [4, 0],
    ]);
});

dataset('valid player counts', [3, 4, 5, 6, 7, 8]);

test('every matchup count matches promptCountForRound', function (int $playerCount) {
    $matchups = $this->pairing->generateRoundMatchups($playerCount);

    expect($matchups)->toHaveCount($this->pairing->promptCountForRound($playerCount));
})->with('valid player counts');

test('every matchup has exactly 2 seats', function (int $playerCount) {
    $matchups = $this->pairing->generateRoundMatchups($playerCount);

    foreach ($matchups as $seatIndices) {
        expect($seatIndices)->toHaveCount(2);
    }
})->with('valid player counts');

test('every seat appears in exactly 2 matchups, against 2 different opponents', function (int $playerCount) {
    $matchups = $this->pairing->generateRoundMatchups($playerCount);

    $opponentsBySeat = array_fill(0, $playerCount, []);
    foreach ($matchups as [$a, $b]) {
        $opponentsBySeat[$a][] = $b;
        $opponentsBySeat[$b][] = $a;
    }

    foreach ($opponentsBySeat as $opponents) {
        expect($opponents)->toHaveCount(2)
            ->and($opponents[0])->not->toBe($opponents[1]);
    }
})->with('valid player counts');

test('no pair of seats faces off twice in the same round', function (int $playerCount) {
    $matchups = $this->pairing->generateRoundMatchups($playerCount);

    $seenPairs = [];
    foreach ($matchups as $seats) {
        sort($seats);
        $key = implode('-', $seats);

        expect($seenPairs)->not->toHaveKey($key);
        $seenPairs[$key] = true;
    }
})->with('valid player counts');
