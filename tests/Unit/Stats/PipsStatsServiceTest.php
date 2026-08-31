<?php

use App\Enums\GameType;
use App\Enums\PipsDifficulty;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use App\Services\Stats\PipsStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = new PipsStatsService;
});

function pipsGame(User $user, string $difficulty, bool $solved, ?int $durationSeconds, int $score): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::Pips]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $score,
        'stats' => [
            'solved' => $solved,
            'duration_seconds' => $durationSeconds,
            'difficulty' => $difficulty,
        ],
    ]);
}

test('gamesForUser only returns the given user\'s own Pips participants, newest first', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $first = pipsGame($user, 'easy', true, 60, 970);
    $second = pipsGame($user, 'hard', true, 300, 850);
    pipsGame($otherUser, 'easy', true, 60, 970);

    $games = $this->service->gamesForUser($user);

    expect($games->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('statsFor reads solved/duration/difficulty straight from stats', function () {
    $user = User::factory()->create();
    $participant = pipsGame($user, 'medium', true, 120, 900);

    $stats = $this->service->statsFor($participant);

    expect($stats)->toBe(['solved' => true, 'durationSeconds' => 120, 'difficulty' => 'medium']);
});

test('bestMetrics picks the fastest solved game, ignoring abandoned games', function () {
    $user = User::factory()->create();

    $fastest = pipsGame($user, 'easy', true, 45, 977);
    pipsGame($user, 'easy', true, 200, 900);
    pipsGame($user, 'easy', false, null, 0);

    $metrics = collect($this->service->bestMetrics($user))->keyBy('label');

    expect($metrics['Fastest solve']['participant']->id)->toBe($fastest->id)
        ->and($metrics['Fastest solve']['value'])->toBe('45s');
});

test('bestMetrics is empty when no game has been solved yet', function () {
    $user = User::factory()->create();
    pipsGame($user, 'easy', false, null, 0);

    expect($this->service->bestMetrics($user))->toBe([]);
});

test('bestMetrics filtered to a difficulty only considers games at that tier', function () {
    $user = User::factory()->create();

    $easyBest = pipsGame($user, 'easy', true, 50, 975);
    $hardBest = pipsGame($user, 'hard', true, 500, 750);

    $easyMetrics = collect($this->service->bestMetrics($user, PipsDifficulty::Easy))->keyBy('label');
    $hardMetrics = collect($this->service->bestMetrics($user, PipsDifficulty::Hard))->keyBy('label');

    expect($easyMetrics['Fastest solve']['participant']->id)->toBe($easyBest->id)
        ->and($hardMetrics['Fastest solve']['participant']->id)->toBe($hardBest->id);
});

test('bestMetricsByDifficulty splits into three independent tiers', function () {
    $user = User::factory()->create();

    $easyBest = pipsGame($user, 'easy', true, 50, 975);
    pipsGame($user, 'medium', false, null, 0);

    $byDifficulty = $this->service->bestMetricsByDifficulty($user);

    expect(collect($byDifficulty['easy'])->firstWhere('label', 'Fastest solve')['participant']->id)->toBe($easyBest->id)
        ->and($byDifficulty['medium'])->toBe([])
        ->and($byDifficulty['hard'])->toBe([]);
});

test('winRate is the percentage of games solved rather than abandoned', function () {
    $user = User::factory()->create();

    pipsGame($user, 'easy', true, 50, 975);
    pipsGame($user, 'easy', true, 60, 970);
    pipsGame($user, 'easy', false, null, 0);
    pipsGame($user, 'easy', false, null, 0);

    expect($this->service->winRate($user))->toBe(50.0);
});

test('winRate is null for a user with no Pips games', function () {
    $user = User::factory()->create();

    expect($this->service->winRate($user))->toBeNull();
});

test('winRateByDifficulty computes each tier independently', function () {
    $user = User::factory()->create();

    pipsGame($user, 'easy', true, 50, 975);
    pipsGame($user, 'hard', false, null, 0);

    $byDifficulty = $this->service->winRateByDifficulty($user);

    expect($byDifficulty['easy'])->toBe(100.0)
        ->and($byDifficulty['hard'])->toBe(0.0)
        ->and($byDifficulty['medium'])->toBeNull();
});
