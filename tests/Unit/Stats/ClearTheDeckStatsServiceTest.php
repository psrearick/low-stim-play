<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use App\Services\Stats\ClearTheDeckStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(ClearTheDeckStatsService::class);
});

function clearTheDeckGame(User $user, int $finalScore, int $roundsWon): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::ClearTheDeck, 'mode' => GameMode::Online]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $finalScore,
        'stats' => ['rounds_won' => $roundsWon],
    ]);
}

test('gamesForUser only returns the given user\'s own Clear the Deck participants, newest first', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $first = clearTheDeckGame($user, 12, 1);
    $second = clearTheDeckGame($user, 8, 2);
    clearTheDeckGame($otherUser, 5, 1);

    // A non-Clear-the-Deck game for the same user shouldn't show up either.
    $feudSession = GameSession::factory()->create(['type' => GameType::FamilyFeud]);
    GameParticipant::factory()->create(['game_session_id' => $feudSession->id, 'user_id' => $user->id]);

    $games = $this->service->gamesForUser($user);

    expect($games->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('statsFor reads the final score and rounds_won back out', function () {
    $user = User::factory()->create();
    $participant = clearTheDeckGame($user, 9, 3);

    $stats = $this->service->statsFor($participant);

    expect($stats['finalScore'])->toBe(9)
        ->and($stats['roundsWon'])->toBe(3);
});

test('statsFor defaults roundsWon to zero for a participant recorded before stats existed', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::ClearTheDeck]);

    $participant = GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => 14,
        'stats' => null,
    ]);

    $stats = $this->service->statsFor($participant);

    expect($stats['finalScore'])->toBe(14)
        ->and($stats['roundsWon'])->toBe(0);
});

test('bestMetrics picks the LOWEST-scoring game, not the highest, since lower is better here', function () {
    $user = User::factory()->create();

    $high = clearTheDeckGame($user, 20, 0);
    $low = clearTheDeckGame($user, 3, 1);

    $metrics = $this->service->bestMetrics($user);
    $bestScore = collect($metrics)->firstWhere('label', 'Fewest points');

    expect($bestScore['participant']->id)->toBe($low->id)
        ->and($bestScore['value'])->toBe('3');

    expect($high->id)->not->toBe($low->id);
});

test('bestMetrics omits the rounds-won metric when this participant never went out first', function () {
    $user = User::factory()->create();
    clearTheDeckGame($user, 10, 0);

    $metrics = $this->service->bestMetrics($user);
    $labels = collect($metrics)->pluck('label')->all();

    expect($labels)->toContain('Fewest points')
        ->and($labels)->not->toContain('Most rounds won');
});

test('bestMetrics includes the rounds-won metric once a game recorded going out first', function () {
    $user = User::factory()->create();
    clearTheDeckGame($user, 10, 2);

    $metrics = $this->service->bestMetrics($user);
    $mostRoundsWon = collect($metrics)->firstWhere('label', 'Most rounds won');

    expect($mostRoundsWon['value'])->toBe('2');
});
