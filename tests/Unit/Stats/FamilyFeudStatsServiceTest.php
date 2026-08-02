<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use App\Services\Stats\FamilyFeudStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(FamilyFeudStatsService::class);
});

/**
 * @param  list<int>  $roundHistory
 */
function feudGame(User $user, array $roundHistory, int $score, int $teamIndex = 0, bool $isCaptain = false): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::FamilyFeud, 'mode' => GameMode::Local]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $score,
        'stats' => ['team_index' => $teamIndex, 'is_captain' => $isCaptain, 'round_history' => $roundHistory],
    ]);
}

test('gamesForUser only returns the given user\'s own Family Feud participants, newest first', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $first = feudGame($user, [10], 10);
    $second = feudGame($user, [20], 20);
    feudGame($otherUser, [30], 30);

    // A non-Family-Feud game for the same user shouldn't show up either.
    $boggleSession = GameSession::factory()->create(['type' => GameType::Boggle]);
    GameParticipant::factory()->create(['game_session_id' => $boggleSession->id, 'user_id' => $user->id]);

    $games = $this->service->gamesForUser($user);

    expect($games->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('statsFor reads team_index/is_captain/round_history back out of stats', function () {
    $user = User::factory()->create();
    $participant = feudGame($user, [30, 0, 40], 70, teamIndex: 1, isCaptain: true);

    $stats = $this->service->statsFor($participant);

    expect($stats['teamIndex'])->toBe(1)
        ->and($stats['isCaptain'])->toBeTrue()
        ->and($stats['roundHistory'])->toBe([30, 0, 40]);
});

test('statsFor defaults gracefully for a participant recorded before stats existed', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::FamilyFeud]);

    $participant = GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => 40,
        'stats' => null,
    ]);

    $stats = $this->service->statsFor($participant);

    expect($stats['teamIndex'])->toBeNull()
        ->and($stats['isCaptain'])->toBeFalse()
        ->and($stats['roundHistory'])->toBe([]);
});

test('bestMetrics picks the highest-scoring game and the biggest single round, omitting a round metric no game ever earned', function () {
    $user = User::factory()->create();

    $low = feudGame($user, [0, 0], 0);
    $high = feudGame($user, [50, 30], 80);

    $metrics = $this->service->bestMetrics($user);
    $labels = collect($metrics)->pluck('label')->all();

    expect($labels)->toContain('Highest score', 'Biggest single round');

    $bestScore = collect($metrics)->firstWhere('label', 'Highest score');
    $bestRound = collect($metrics)->firstWhere('label', 'Biggest single round');

    expect($bestScore['participant']->id)->toBe($high->id)
        ->and($bestScore['value'])->toBe('80')
        ->and($bestRound['participant']->id)->toBe($high->id)
        ->and($bestRound['value'])->toBe('50');

    expect($low->id)->not->toBe($high->id);
});

test('bestMetrics omits the biggest-round metric when no game ever scored above zero in a round', function () {
    $user = User::factory()->create();
    feudGame($user, [0, 0], 0);

    $metrics = $this->service->bestMetrics($user);
    $labels = collect($metrics)->pluck('label')->all();

    expect($labels)->toContain('Highest score')
        ->and($labels)->not->toContain('Biggest single round');
});
