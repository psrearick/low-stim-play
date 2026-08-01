<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\YahtzeeCategory;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use App\Services\Stats\YahtzeeStatsService;
use App\Services\Yahtzee\GameEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(YahtzeeStatsService::class);
});

/**
 * @param  array<string, int|null>  $scores
 */
function yahtzeeGame(User $user, array $scores, int $score, int $yahtzeeBonusCount = 0): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::Yahtzee, 'mode' => GameMode::Local]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $score,
        'stats' => ['scores' => $scores, 'yahtzee_bonus_count' => $yahtzeeBonusCount],
    ]);
}

test('gamesForUser only returns the given user\'s own Yahtzee participants, newest first', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $first = yahtzeeGame($user, ['ones' => 3], 3);
    $second = yahtzeeGame($user, ['twos' => 4], 4);
    yahtzeeGame($otherUser, ['threes' => 6], 6);

    // A non-Yahtzee game for the same user shouldn't show up either.
    $boggleSession = GameSession::factory()->create(['type' => GameType::Boggle]);
    GameParticipant::factory()->create(['game_session_id' => $boggleSession->id, 'user_id' => $user->id]);

    $games = $this->service->gamesForUser($user);

    expect($games->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('statsFor derives the upper subtotal and bonus from stored category scores via GameEngine', function () {
    $user = User::factory()->create();

    // Upper section totals exactly 63 -- right at the bonus threshold.
    $participant = yahtzeeGame($user, [
        'ones' => 3, 'twos' => 6, 'threes' => 9, 'fours' => 12, 'fives' => 15, 'sixes' => 18,
        'three_of_a_kind' => 20, 'yahtzee' => 50,
    ], score: 133, yahtzeeBonusCount: 1);

    $stats = $this->service->statsFor($participant);

    expect($stats['upperSubtotal'])->toBe(63)
        ->and($stats['upperBonus'])->toBe(35)
        ->and($stats['lowerSubtotal'])->toBe(70)
        ->and($stats['yahtzeeBonusCount'])->toBe(1)
        ->and($stats['yahtzeeBonusPoints'])->toBe(app(GameEngine::class)->yahtzeeBonusPoints(1));
});

test('statsFor reports no upper bonus for a participant recorded before scores were persisted', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::Yahtzee]);

    // Mirrors a participant recorded before this app started storing
    // per-category scores -- only yahtzee_bonus_count existed then.
    $participant = GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => 20,
        'stats' => ['yahtzee_bonus_count' => 0],
    ]);

    $stats = $this->service->statsFor($participant);

    expect($stats['scores'])->toBe([])
        ->and($stats['upperSubtotal'])->toBe(0)
        ->and($stats['upperBonus'])->toBe(0);
});

test('bestMetrics picks the highest-scoring game, and omits bonus metrics no game ever earned', function () {
    $user = User::factory()->create();

    $low = yahtzeeGame($user, ['ones' => 3], score: 3);
    $high = yahtzeeGame($user, ['sixes' => 24], score: 24);

    $metrics = $this->service->bestMetrics($user);
    $labels = collect($metrics)->pluck('label')->all();

    expect($labels)->toContain('Highest score')
        ->and($labels)->not->toContain('Most Yahtzee bonuses')
        ->and($labels)->not->toContain('Best upper section');

    $best = collect($metrics)->firstWhere('label', 'Highest score');
    expect($best['participant']->id)->toBe($high->id)
        ->and($best['value'])->toBe('24');
    expect($low->id)->not->toBe($high->id);
});

test('bestCategoryScores picks this user\'s personal best per category, linking to that game', function () {
    $user = User::factory()->create();

    $weak = yahtzeeGame($user, ['sixes' => 12], score: 12);
    $strong = yahtzeeGame($user, ['sixes' => 24], score: 24);

    $best = $this->service->bestCategoryScores($user);
    $sixesEntry = collect($best)->firstWhere('category', YahtzeeCategory::Sixes);

    expect($sixesEntry['score'])->toBe(24)
        ->and($sixesEntry['participant']->id)->toBe($strong->id);
    expect($weak->id)->not->toBe($strong->id);
});

test('bestCategoryScores omits a category no game has ever scored', function () {
    $user = User::factory()->create();
    yahtzeeGame($user, ['ones' => 3], score: 3);

    $best = $this->service->bestCategoryScores($user);
    $categories = collect($best)->pluck('category');

    expect($categories)->not->toContain(YahtzeeCategory::Yahtzee);
});
