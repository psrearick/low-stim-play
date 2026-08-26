<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use App\Services\Stats\QuartilesStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(QuartilesStatsService::class);
});

function quartilesGame(User $user, int $score, int $wordsFound = 0, int $quartilesFound = 0, ?int $totalValidWords = null): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::Quartiles, 'mode' => GameMode::Solo]);

    $stats = ['words_found' => $wordsFound, 'quartiles_found' => $quartilesFound];

    if ($totalValidWords !== null) {
        $stats['total_valid_words'] = $totalValidWords;
    }

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $score,
        'stats' => $stats,
    ]);
}

test('gamesForUser only returns the given user\'s own Quads participants, newest first', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $first = quartilesGame($user, 10);
    $second = quartilesGame($user, 20);
    quartilesGame($otherUser, 30);

    // A non-Quartiles game for the same user shouldn't show up either.
    $boggleSession = GameSession::factory()->create(['type' => GameType::Boggle]);
    GameParticipant::factory()->create(['game_session_id' => $boggleSession->id, 'user_id' => $user->id]);

    $games = $this->service->gamesForUser($user);

    expect($games->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('statsFor reads the raw stored counters and computes percentFound', function () {
    $user = User::factory()->create();
    $participant = quartilesGame($user, score: 62, wordsFound: 8, quartilesFound: 3, totalValidWords: 16);

    expect($this->service->statsFor($participant))->toBe([
        'wordsFound' => 8,
        'quartilesFound' => 3,
        'totalValidWords' => 16,
        'percentFound' => 50.0,
    ]);
});

test('statsFor defaults to zero/null for a participant with no stats recorded', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::Quartiles]);
    $participant = GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => 0,
        'stats' => [],
    ]);

    expect($this->service->statsFor($participant))->toBe([
        'wordsFound' => 0,
        'quartilesFound' => 0,
        'totalValidWords' => null,
        'percentFound' => null,
    ]);
});

test('bestMetrics picks the highest-scoring game, and omits quartiles found if no game ever found one', function () {
    $user = User::factory()->create();

    $low = quartilesGame($user, score: 4);
    $high = quartilesGame($user, score: 40, wordsFound: 10);

    $metrics = $this->service->bestMetrics($user);
    $labels = collect($metrics)->pluck('label')->all();

    expect($labels)->toContain('Highest score')
        ->and($labels)->toContain('Most words found')
        ->and($labels)->not->toContain('Most Quads found');

    $best = collect($metrics)->firstWhere('label', 'Highest score');
    expect($best['participant']->id)->toBe($high->id)
        ->and($best['value'])->toBe('40');
    expect($low->id)->not->toBe($high->id);
});

test('bestMetrics includes most Quartiles found once any game has found one', function () {
    $user = User::factory()->create();
    $game = quartilesGame($user, score: 48, quartilesFound: 5);

    $metrics = $this->service->bestMetrics($user);
    $quartilesMetric = collect($metrics)->firstWhere('label', 'Most Quads found');

    expect($quartilesMetric['value'])->toBe('5/5')
        ->and($quartilesMetric['participant']->id)->toBe($game->id);
});
