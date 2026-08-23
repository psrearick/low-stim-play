<?php

use App\Services\Punchline\BallotService;
use Tests\TestCase;

// Cache::/now() need a booted app (the facade root), same reasoning
// tests/Unit/FamilyFeud/JudgeServiceTest.php already documents for Http::fake().
uses(TestCase::class);

beforeEach(function () {
    $this->ballot = new BallotService;
    $this->key = 'punchline:1:round:1:matchup:r1-0';
});

test('a ballot starts incomplete, with nobody having submitted', function () {
    expect($this->ballot->submittedCount($this->key, 0))->toBe(0)
        ->and($this->ballot->isComplete($this->key, [0, 3], 1))->toBeFalse();
});

test('isComplete only turns true once every expected seat has filed answersPerSeat answers', function () {
    $this->ballot->submit($this->key, 0, '0-0', 'Answer from seat 0');

    expect($this->ballot->isComplete($this->key, [0, 3], 1))->toBeFalse();

    $this->ballot->submit($this->key, 3, '3-0', 'Answer from seat 3');

    expect($this->ballot->isComplete($this->key, [0, 3], 1))->toBeTrue();
});

test('isComplete requires every seat to reach answersPerSeat, not just submit once', function () {
    $this->ballot->submit($this->key, 0, '0-0', 'first');
    $this->ballot->submit($this->key, 0, '0-1', 'second');
    $this->ballot->submit($this->key, 0, '0-2', 'third');

    expect($this->ballot->isComplete($this->key, [0], 3))->toBeTrue()
        ->and($this->ballot->submittedCount($this->key, 0))->toBe(3);
});

test('revealAnonymized returns every submitted answer\'s text with no seat mapping attached', function () {
    $this->ballot->submit($this->key, 0, '0-0', 'Sam\'s answer');
    $this->ballot->submit($this->key, 3, '3-0', 'Jordan\'s answer');

    $revealed = $this->ballot->revealAnonymized($this->key);

    expect($revealed)->toHaveCount(2);

    foreach ($revealed as $entry) {
        expect($entry)->toHaveKeys(['answerId', 'text'])
            ->not->toHaveKey('seatIndex');
    }

    $texts = array_column($revealed, 'text');
    expect($texts)->toContain('Sam\'s answer', 'Jordan\'s answer');
});

test('a vote is recorded and hasVoted reflects it', function () {
    expect($this->ballot->hasVoted($this->key, 5))->toBeFalse();

    $this->ballot->vote($this->key, 5, ['0-0']);

    expect($this->ballot->hasVoted($this->key, 5))->toBeTrue()
        ->and($this->ballot->votedSeats($this->key))->toBe([5]);
});

test('a later vote from the same seat overwrites the earlier one, not adds to it', function () {
    $this->ballot->vote($this->key, 5, ['0-0']);
    $this->ballot->vote($this->key, 5, ['3-0']);

    expect($this->ballot->tally($this->key))->toBe(['3-0' => 1]);
});

test('tally aggregates votes per answer across every voter, including a voter stacking multiple votes on one answer', function () {
    $this->ballot->vote($this->key, 1, ['0-0']);
    $this->ballot->vote($this->key, 2, ['0-0']);
    $this->ballot->vote($this->key, 4, ['3-0', '3-0', '3-0']); // final-round-style stacking

    expect($this->ballot->tally($this->key))->toBe(['0-0' => 2, '3-0' => 3]);
});

test('tally is keyed by answerId only, never by voter seat index', function () {
    $this->ballot->vote($this->key, 1, ['0-0']);
    $this->ballot->vote($this->key, 2, ['0-0']);

    $tally = $this->ballot->tally($this->key);

    expect(array_keys($tally))->toBe(['0-0'])
        ->and($tally['0-0'])->toBe(2);
});

test('authors maps each answer back to its real seat, read fresh from submissions', function () {
    $this->ballot->submit($this->key, 0, '0-0', 'text');
    $this->ballot->submit($this->key, 3, '3-0', 'text');

    expect($this->ballot->authors($this->key))->toBe(['0-0' => 0, '3-0' => 3]);
});

test('clear drops both submissions and votes for this ballot only', function () {
    $otherKey = 'punchline:1:round:1:matchup:r1-1';

    $this->ballot->submit($this->key, 0, '0-0', 'text');
    $this->ballot->vote($this->key, 5, ['0-0']);
    $this->ballot->submit($otherKey, 1, '1-0', 'other text');

    $this->ballot->clear($this->key);

    expect($this->ballot->submittedCount($this->key, 0))->toBe(0)
        ->and($this->ballot->tally($this->key))->toBe([])
        ->and($this->ballot->authors($this->key))->toBe([])
        ->and($this->ballot->submittedCount($otherKey, 1))->toBe(1); // untouched
});
