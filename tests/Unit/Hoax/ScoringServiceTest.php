<?php

use App\Services\Hoax\ScoringService;

beforeEach(function () {
    $this->scoring = new ScoringService;
});

test('a voter who picks the real answer earns TRUTH_POINTS and is listed as having found the truth', function () {
    $result = $this->scoring->scoreRound(
        voteByVoterSeat: [1 => 'truth-0'],
        authorsByAnswerId: ['truth-0' => ScoringService::TRUTH_SEAT_INDEX, '0-0' => 0],
        truthAnswerId: 'truth-0',
    );

    expect($result['pointsBySeat'])->toBe([1 => 1000])
        ->and($result['seatsWhoFoundTruth'])->toBe([1]);
});

test('a lie\'s author earns FOOL_POINTS for each other voter who picks it, stacking', function () {
    $result = $this->scoring->scoreRound(
        voteByVoterSeat: [1 => '0-0', 2 => '0-0', 3 => 'truth-0'],
        authorsByAnswerId: ['truth-0' => ScoringService::TRUTH_SEAT_INDEX, '0-0' => 0],
        truthAnswerId: 'truth-0',
    );

    expect($result['pointsBySeat'][0])->toBe(1000) // fooled 2 voters x 500
        ->and($result['pointsBySeat'][3])->toBe(1000) // found the truth
        ->and($result['seatsWhoFoundTruth'])->toBe([3]);
});

test('a lie nobody voted for earns its author nothing', function () {
    $result = $this->scoring->scoreRound(
        voteByVoterSeat: [1 => 'truth-0'],
        authorsByAnswerId: ['truth-0' => ScoringService::TRUTH_SEAT_INDEX, '0-0' => 0, '2-0' => 2],
        truthAnswerId: 'truth-0',
    );

    expect($result['pointsBySeat'])->not->toHaveKey(0)
        ->and($result['pointsBySeat'])->not->toHaveKey(2);
});

test('nobody finding the truth leaves seatsWhoFoundTruth empty and awards no truth points', function () {
    $result = $this->scoring->scoreRound(
        voteByVoterSeat: [1 => '0-0', 2 => '3-0'],
        authorsByAnswerId: ['truth-0' => ScoringService::TRUTH_SEAT_INDEX, '0-0' => 0, '3-0' => 3],
        truthAnswerId: 'truth-0',
    );

    expect($result['seatsWhoFoundTruth'])->toBe([])
        ->and($result['pointsBySeat'])->toBe([0 => 500, 3 => 500]);
});

test('the truth sentinel author never receives fool points, even indirectly', function () {
    $result = $this->scoring->scoreRound(
        voteByVoterSeat: [1 => 'truth-0', 2 => 'truth-0'],
        authorsByAnswerId: ['truth-0' => ScoringService::TRUTH_SEAT_INDEX],
        truthAnswerId: 'truth-0',
    );

    expect($result['pointsBySeat'])->not->toHaveKey(ScoringService::TRUTH_SEAT_INDEX);
});

test('votesByAnswerId aggregates every vote per answer, matching what actually happened', function () {
    $result = $this->scoring->scoreRound(
        voteByVoterSeat: [1 => '0-0', 2 => '0-0', 3 => 'truth-0', 4 => '3-0'],
        authorsByAnswerId: ['truth-0' => ScoringService::TRUTH_SEAT_INDEX, '0-0' => 0, '3-0' => 3],
        truthAnswerId: 'truth-0',
    );

    expect($result['votesByAnswerId'])->toBe(['0-0' => 2, 'truth-0' => 1, '3-0' => 1]);
});

test('a seat with no votes at all is simply absent from pointsBySeat, not present with zero', function () {
    $result = $this->scoring->scoreRound(
        voteByVoterSeat: [1 => 'truth-0'],
        authorsByAnswerId: ['truth-0' => ScoringService::TRUTH_SEAT_INDEX, '0-0' => 0],
        truthAnswerId: 'truth-0',
    );

    expect($result['pointsBySeat'])->not->toHaveKey(0);
});
