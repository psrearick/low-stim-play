<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use App\Services\Stats\PunchlineStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(PunchlineStatsService::class);
});

function punchlineGame(User $user, int $score, array $pointsByRound, int $sweepsWon): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::Punchline, 'mode' => GameMode::Online]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $score,
        'stats' => ['pointsByRound' => $pointsByRound, 'sweepsWon' => $sweepsWon],
    ]);
}

test('gamesForUser only returns the given user\'s own Punchline participants, newest first', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $first = punchlineGame($user, 1000, [1000, 0, 0], 1);
    $second = punchlineGame($user, 2000, [1000, 1000, 0], 2);
    punchlineGame($otherUser, 500, [500, 0, 0], 0);

    // A non-Punchline game for the same user shouldn't show up either.
    $matchSession = GameSession::factory()->create(['type' => GameType::AppleMatch]);
    GameParticipant::factory()->create(['game_session_id' => $matchSession->id, 'user_id' => $user->id]);

    $games = $this->service->gamesForUser($user);

    expect($games->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('statsFor reads score/pointsByRound/sweepsWon back out of the participant', function () {
    $user = User::factory()->create();
    $participant = punchlineGame($user, 3600, [1000, 1200, 1400], 2);

    $stats = $this->service->statsFor($participant);

    expect($stats['score'])->toBe(3600)
        ->and($stats['pointsByRound'])->toBe([1000, 1200, 1400])
        ->and($stats['sweepsWon'])->toBe(2);
});

test('statsFor defaults gracefully for a participant recorded before stats existed', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::Punchline]);

    $participant = GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => 500,
        'stats' => null,
    ]);

    $stats = $this->service->statsFor($participant);

    expect($stats['score'])->toBe(500)
        ->and($stats['pointsByRound'])->toBe([0, 0, 0])
        ->and($stats['sweepsWon'])->toBe(0);
});

test('bestMetrics picks the highest-scoring game and the most sweeps won, omitting the sweeps metric when nobody ever swept', function () {
    $user = User::factory()->create();

    $low = punchlineGame($user, 1000, [500, 500, 0], 0);
    $high = punchlineGame($user, 4000, [1000, 1200, 1800], 3);

    $metrics = $this->service->bestMetrics($user);
    $labels = collect($metrics)->pluck('label')->all();

    expect($labels)->toContain('Highest score', 'Most sweeps won');

    $bestScore = collect($metrics)->firstWhere('label', 'Highest score');
    $mostSweeps = collect($metrics)->firstWhere('label', 'Most sweeps won');

    expect($bestScore['participant']->id)->toBe($high->id)
        ->and($bestScore['value'])->toBe('4000')
        ->and($mostSweeps['participant']->id)->toBe($high->id)
        ->and($mostSweeps['value'])->toBe('3');

    expect($low->id)->not->toBe($high->id);
});

test('bestMetrics omits the sweeps metric when no game ever recorded a sweep', function () {
    $user = User::factory()->create();
    punchlineGame($user, 1500, [500, 500, 500], 0);

    $metrics = $this->service->bestMetrics($user);
    $labels = collect($metrics)->pluck('label')->all();

    expect($labels)->toContain('Highest score')
        ->and($labels)->not->toContain('Most sweeps won');
});

test('bestMetrics returns nothing for a user with no Punchline games', function () {
    $user = User::factory()->create();

    expect($this->service->bestMetrics($user))->toBe([]);
});
