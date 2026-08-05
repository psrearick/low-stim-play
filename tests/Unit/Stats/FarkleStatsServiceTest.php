<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use App\Services\Stats\FarkleStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(FarkleStatsService::class);
});

function farkleGame(User $user, int $score, int $farkleCount = 0, int $hotDiceCount = 0, int $bestTurnScore = 0): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::Farkle, 'mode' => GameMode::Local]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $score,
        'stats' => [
            'farkle_count' => $farkleCount,
            'hot_dice_count' => $hotDiceCount,
            'best_turn_score' => $bestTurnScore,
        ],
    ]);
}

test('gamesForUser only returns the given user\'s own Farkle participants, newest first', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $first = farkleGame($user, 1000);
    $second = farkleGame($user, 2000);
    farkleGame($otherUser, 3000);

    // A non-Farkle game for the same user shouldn't show up either.
    $boggleSession = GameSession::factory()->create(['type' => GameType::Boggle]);
    GameParticipant::factory()->create(['game_session_id' => $boggleSession->id, 'user_id' => $user->id]);

    $games = $this->service->gamesForUser($user);

    expect($games->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('statsFor reads the raw stored counters directly', function () {
    $user = User::factory()->create();
    $participant = farkleGame($user, score: 6200, farkleCount: 2, hotDiceCount: 1, bestTurnScore: 3200);

    $stats = $this->service->statsFor($participant);

    expect($stats)->toBe(['farkleCount' => 2, 'hotDiceCount' => 1, 'bestTurnScore' => 3200]);
});

test('statsFor defaults to zero for a participant with no stats recorded', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::Farkle]);
    $participant = GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => 0,
        'stats' => [],
    ]);

    expect($this->service->statsFor($participant))->toBe(['farkleCount' => 0, 'hotDiceCount' => 0, 'bestTurnScore' => 0]);
});

test('bestMetrics picks the highest-scoring game, and omits metrics no game ever earned', function () {
    $user = User::factory()->create();

    $low = farkleGame($user, score: 500);
    $high = farkleGame($user, score: 8000, bestTurnScore: 8000);

    $metrics = $this->service->bestMetrics($user);
    $labels = collect($metrics)->pluck('label')->all();

    expect($labels)->toContain('Highest score')
        ->and($labels)->toContain('Best single turn')
        ->and($labels)->not->toContain('Most hot dice');

    $best = collect($metrics)->firstWhere('label', 'Highest score');
    expect($best['participant']->id)->toBe($high->id)
        ->and($best['value'])->toBe('8000');
    expect($low->id)->not->toBe($high->id);
});

test('bestMetrics includes most hot dice once any game has earned one', function () {
    $user = User::factory()->create();
    $game = farkleGame($user, score: 4000, hotDiceCount: 2);

    $metrics = $this->service->bestMetrics($user);
    $hotDiceMetric = collect($metrics)->firstWhere('label', 'Most hot dice');

    expect($hotDiceMetric['value'])->toBe('2')
        ->and($hotDiceMetric['participant']->id)->toBe($game->id);
});
