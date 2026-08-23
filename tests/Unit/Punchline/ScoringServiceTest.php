<?php

use App\Services\Punchline\ScoringService;

beforeEach(function () {
    $this->scoring = new ScoringService;
});

test('poolForRound returns the escalating pool for each of the 3 rounds', function () {
    expect($this->scoring->poolForRound(1))->toBe(1000)
        ->and($this->scoring->poolForRound(2))->toBe(2000)
        ->and($this->scoring->poolForRound(3))->toBe(3000);
});

test('poolForRound rejects an undefined round', function () {
    expect(fn () => $this->scoring->poolForRound(4))->toThrow(InvalidArgumentException::class);
});

test('roundToNearest50 rounds to the nearest multiple of 50', function () {
    expect($this->scoring->roundToNearest50(833.33))->toBe(850)
        ->and($this->scoring->roundToNearest50(666.67))->toBe(650)
        ->and($this->scoring->roundToNearest50(500.0))->toBe(500)
        ->and($this->scoring->roundToNearest50(24.0))->toBe(0)
        ->and($this->scoring->roundToNearest50(25.0))->toBe(50);
});

test('a 6-0 unanimous vote sweeps: the winner scores the full pool plus a bonus, the loser scores nothing', function () {
    $result = $this->scoring->scoreMatchup(1000, ['a' => 6, 'b' => 0], ['a', 'b']);

    expect($result['totalVotes'])->toBe(6)
        ->and($result['sweep'])->toBeTrue()
        ->and($result['sweepAnswerId'])->toBe('a')
        ->and($result['sweepBonus'])->toBe(200) // round50(1000 * 0.2)
        ->and($result['pointsByAnswerId']['a'])->toBe(1200) // 1000 (100% of pool) + 200 bonus
        ->and($result['pointsByAnswerId']['b'])->toBe(0);
});

test('a 5-1 split scores proportionally to vote share, no sweep', function () {
    $result = $this->scoring->scoreMatchup(1000, ['a' => 5, 'b' => 1], ['a', 'b']);

    expect($result['sweep'])->toBeFalse()
        ->and($result['sweepBonus'])->toBe(0)
        ->and($result['pointsByAnswerId']['a'])->toBe(850) // round50(1000 * 5/6)
        ->and($result['pointsByAnswerId']['b'])->toBe(150); // round50(1000 * 1/6)
});

test('a 4-2 split scores proportionally to vote share', function () {
    $result = $this->scoring->scoreMatchup(1000, ['a' => 4, 'b' => 2], ['a', 'b']);

    expect($result['pointsByAnswerId']['a'])->toBe(650)
        ->and($result['pointsByAnswerId']['b'])->toBe(350);
});

test('a 3-3 tie splits the pool exactly evenly', function () {
    $result = $this->scoring->scoreMatchup(1000, ['a' => 3, 'b' => 3], ['a', 'b']);

    expect($result['pointsByAnswerId']['a'])->toBe(500)
        ->and($result['pointsByAnswerId']['b'])->toBe(500)
        ->and($result['sweep'])->toBeFalse();
});

test('zero total votes splits the pool evenly across every answer instead of scoring nothing', function () {
    $result = $this->scoring->scoreMatchup(1000, [], ['a', 'b']);

    expect($result['totalVotes'])->toBe(0)
        ->and($result['pointsByAnswerId'])->toBe(['a' => 500, 'b' => 500])
        ->and($result['sweep'])->toBeFalse();
});

test('zero total votes splits evenly across a 3-way matchup too', function () {
    $result = $this->scoring->scoreMatchup(999, [], ['a', 'b', 'c']);

    // round50(999 / 3) = round50(333) = 350
    expect($result['pointsByAnswerId'])->toBe(['a' => 350, 'b' => 350, 'c' => 350]);
});

test('rounding to the nearest 50 is not remainder-corrected, so a 1/1/4-of-6 split can undershoot the pool', function () {
    $result = $this->scoring->scoreMatchup(1000, ['a' => 1, 'b' => 1, 'c' => 4], ['a', 'b', 'c']);

    expect($result['pointsByAnswerId'])->toBe(['a' => 150, 'b' => 150, 'c' => 650]);

    $sum = array_sum($result['pointsByAnswerId']);
    expect($sum)->toBe(950)->not->toBe(1000); // the accepted, documented 50-point drift
});

test('sweep is defined identically at the larger final-round scale: unanimous among every cast vote, not just a majority', function () {
    $votes = ['a' => 24, 'b' => 0, 'c' => 0, 'd' => 0, 'e' => 0, 'f' => 0, 'g' => 0, 'h' => 0];
    $answerIds = array_keys($votes);

    $result = $this->scoring->scoreMatchup(3000, $votes, $answerIds);

    expect($result['sweep'])->toBeTrue()
        ->and($result['sweepAnswerId'])->toBe('a')
        ->and($result['sweepBonus'])->toBe(600) // round50(3000 * 0.2)
        ->and($result['pointsByAnswerId']['a'])->toBe(3600) // 3000 (100%) + 600 bonus
        ->and($result['pointsByAnswerId']['b'])->toBe(0);
});

test('a near-unanimous final-round vote (one holdout) is not a sweep, even with a huge majority', function () {
    $votes = ['a' => 23, 'b' => 1, 'c' => 0, 'd' => 0, 'e' => 0, 'f' => 0, 'g' => 0, 'h' => 0];

    $result = $this->scoring->scoreMatchup(3000, $votes, array_keys($votes));

    expect($result['sweep'])->toBeFalse()
        ->and($result['sweepBonus'])->toBe(0);
});

test('every answer id is present in the result even if it received zero votes', function () {
    $result = $this->scoring->scoreMatchup(1000, ['a' => 6], ['a', 'b', 'c']);

    expect($result['pointsByAnswerId'])->toHaveKeys(['a', 'b', 'c'])
        ->and($result['pointsByAnswerId']['b'])->toBe(0)
        ->and($result['pointsByAnswerId']['c'])->toBe(0);
});
