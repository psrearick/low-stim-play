<?php

use App\Services\Punchline\PairingService;

beforeEach(function () {
    $this->pairing = new PairingService;
});

test('promptCountForRound returns the player count for an even count, one fewer for an odd count', function () {
    expect($this->pairing->promptCountForRound(4))->toBe(4)
        ->and($this->pairing->promptCountForRound(6))->toBe(6)
        ->and($this->pairing->promptCountForRound(8))->toBe(8)
        ->and($this->pairing->promptCountForRound(3))->toBe(2)
        ->and($this->pairing->promptCountForRound(5))->toBe(4)
        ->and($this->pairing->promptCountForRound(7))->toBe(6);
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

dataset('valid player counts', [3, 4, 5, 6, 7, 8]);

test('every matchup count matches promptCountForRound', function (int $playerCount) {
    $matchups = $this->pairing->generateRoundMatchups($playerCount);

    expect($matchups)->toHaveCount($this->pairing->promptCountForRound($playerCount));
})->with('valid player counts');

test('every seat appears in exactly 2 matchups', function (int $playerCount) {
    $matchups = $this->pairing->generateRoundMatchups($playerCount);

    $appearances = array_fill(0, $playerCount, 0);
    foreach ($matchups as $seatIndices) {
        foreach ($seatIndices as $seat) {
            $appearances[$seat]++;
        }
    }

    foreach ($appearances as $count) {
        expect($count)->toBe(2);
    }
})->with('valid player counts');

test('total seat-appearances across the round always equals 2 x player count', function (int $playerCount) {
    $matchups = $this->pairing->generateRoundMatchups($playerCount);

    $total = array_sum(array_map(fn (array $seats) => count($seats), $matchups));

    expect($total)->toBe(2 * $playerCount);
})->with('valid player counts');

test('every matchup has either 2 or 3 seats, never anything else', function (int $playerCount) {
    $matchups = $this->pairing->generateRoundMatchups($playerCount);

    foreach ($matchups as $seatIndices) {
        expect(count($seatIndices))->toBeIn([2, 3]);
    }
})->with('valid player counts');

test('an odd player count produces exactly one triple per pass (two total), an even count produces none', function (int $playerCount) {
    $matchups = $this->pairing->generateRoundMatchups($playerCount);

    $tripleCount = count(array_filter($matchups, fn (array $seats) => count($seats) === 3));

    expect($tripleCount)->toBe($playerCount % 2 === 0 ? 0 : 2);
})->with('valid player counts');

test('no two seats within the pairing "core" (the provably-achievable subset of no-repeat) share a matchup twice', function (int $playerCount) {
    $matchups = $this->pairing->generateRoundMatchups($playerCount);

    // The core is every seat that never appears in a triple -- for P=5
    // that's seats 0-1 (a documented, unavoidable repeat), so this
    // assertion only runs when the core actually has more than 2 seats
    // (P=3's core is empty, P=5's is a single unavoidable pair).
    $tripleSeats = [];
    foreach ($matchups as $seats) {
        if (count($seats) === 3) {
            $tripleSeats = $seats;
        }
    }
    $coreSeats = array_values(array_diff(range(0, $playerCount - 1), $tripleSeats));

    if (count($coreSeats) <= 2) {
        expect(true)->toBeTrue(); // nothing to assert -- see docblock

        return;
    }

    $seenPairs = [];
    foreach ($matchups as $seats) {
        if (count($seats) !== 2) {
            continue;
        }

        sort($seats);
        $key = implode('-', $seats);

        expect($seenPairs)->not->toHaveKey($key);
        $seenPairs[$key] = true;
    }
})->with('valid player counts');

test('P=3 degenerates to the same trio answering both prompts', function () {
    $matchups = $this->pairing->generateRoundMatchups(3);

    expect($matchups)->toBe([
        [0, 1, 2],
        [0, 1, 2],
    ]);
});

test('P=5\'s core pair unavoidably repeats across both passes', function () {
    $matchups = $this->pairing->generateRoundMatchups(5);

    $pairs = array_values(array_filter($matchups, fn (array $seats) => count($seats) === 2));

    expect($pairs)->toBe([[1, 0], [1, 0]]);
});
