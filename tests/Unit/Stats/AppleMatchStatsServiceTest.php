<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use App\Services\Stats\AppleMatchStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(AppleMatchStatsService::class);
});

function appleMatchGame(User $user, int $greenCardsWon, int $roundsJudged): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::AppleMatch, 'mode' => GameMode::Online]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $greenCardsWon,
        'stats' => ['green_cards_won' => $greenCardsWon, 'rounds_judged' => $roundsJudged],
    ]);
}

test('gamesForUser only returns the given user\'s own Best Match participants, newest first', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $first = appleMatchGame($user, 2, 1);
    $second = appleMatchGame($user, 4, 2);
    appleMatchGame($otherUser, 3, 1);

    // A non-Best-Match game for the same user shouldn't show up either.
    $feudSession = GameSession::factory()->create(['type' => GameType::FamilyFeud]);
    GameParticipant::factory()->create(['game_session_id' => $feudSession->id, 'user_id' => $user->id]);

    $games = $this->service->gamesForUser($user);

    expect($games->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('statsFor reads green_cards_won/rounds_judged back out of stats', function () {
    $user = User::factory()->create();
    $participant = appleMatchGame($user, 4, 3);

    $stats = $this->service->statsFor($participant);

    expect($stats['greenCardsWon'])->toBe(4)
        ->and($stats['roundsJudged'])->toBe(3);
});

test('statsFor defaults gracefully for a participant recorded before stats existed', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::AppleMatch]);

    $participant = GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => 2,
        'stats' => null,
    ]);

    $stats = $this->service->statsFor($participant);

    expect($stats['greenCardsWon'])->toBe(2)
        ->and($stats['roundsJudged'])->toBe(0);
});

test('bestMetrics picks the highest-scoring game and the most rounds judged, omitting the judged metric when nobody ever judged', function () {
    $user = User::factory()->create();

    $low = appleMatchGame($user, 1, 0);
    $high = appleMatchGame($user, 5, 2);

    $metrics = $this->service->bestMetrics($user);
    $labels = collect($metrics)->pluck('label')->all();

    expect($labels)->toContain('Most green cards won', 'Most rounds judged');

    $bestScore = collect($metrics)->firstWhere('label', 'Most green cards won');
    $mostJudged = collect($metrics)->firstWhere('label', 'Most rounds judged');

    expect($bestScore['participant']->id)->toBe($high->id)
        ->and($bestScore['value'])->toBe('5')
        ->and($mostJudged['participant']->id)->toBe($high->id)
        ->and($mostJudged['value'])->toBe('2');

    expect($low->id)->not->toBe($high->id);
});

test('bestMetrics omits the rounds-judged metric when no game ever recorded judging a round', function () {
    $user = User::factory()->create();
    appleMatchGame($user, 2, 0);

    $metrics = $this->service->bestMetrics($user);
    $labels = collect($metrics)->pluck('label')->all();

    expect($labels)->toContain('Most green cards won')
        ->and($labels)->not->toContain('Most rounds judged');
});
