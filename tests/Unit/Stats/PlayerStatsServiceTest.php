<?php

use App\Enums\GameMode;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use App\Services\Stats\PlayerStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = new PlayerStatsService;
});

function participantFor(User $user, GameType $type, int $score, ?GameResult $result): GameParticipant
{
    $session = GameSession::factory()->create(['type' => $type, 'mode' => GameMode::Solo]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $score,
        'result' => $result,
    ]);
}

test('a user with no games has no stats', function () {
    $user = User::factory()->create();

    expect($this->service->forUser($user))->toBeEmpty();
});

test('stats are grouped and aggregated per game type', function () {
    $user = User::factory()->create();

    participantFor($user, GameType::Boggle, 100, GameResult::Win);
    participantFor($user, GameType::Boggle, 50, GameResult::Loss);
    participantFor($user, GameType::Yahtzee, 200, GameResult::Win);

    $stats = $this->service->forUser($user)->keyBy(fn (array $stat) => $stat['type']->value);

    expect($stats['boggle']['gamesPlayed'])->toBe(2)
        ->and($stats['boggle']['wins'])->toBe(1)
        ->and($stats['boggle']['winRate'])->toBe(50)
        ->and($stats['boggle']['avgScore'])->toBe(75.0)
        ->and($stats['yahtzee']['gamesPlayed'])->toBe(1)
        ->and($stats['yahtzee']['winRate'])->toBe(100);
});

test('a game with no result recorded (e.g. solo play) still counts toward games played', function () {
    $user = User::factory()->create();

    participantFor($user, GameType::Boggle, 40, null);

    $stats = $this->service->forUser($user)->first();

    expect($stats['gamesPlayed'])->toBe(1)
        ->and($stats['wins'])->toBe(0)
        ->and($stats['winRate'])->toBe(0);
});

test('recentGames only returns the given user\'s own participants, newest first', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $first = participantFor($user, GameType::Boggle, 10, null);
    $second = participantFor($user, GameType::Yahtzee, 20, null);
    participantFor($otherUser, GameType::Boggle, 999, null);

    $recent = $this->service->recentGames($user);

    expect($recent)->toHaveCount(2)
        ->and($recent->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('recentGames respects the limit', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        participantFor($user, GameType::Boggle, $i, null);
    }

    expect($this->service->recentGames($user, limit: 3))->toHaveCount(3);
});
