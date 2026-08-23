<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use App\Services\Stats\SpellingBeeStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(SpellingBeeStatsService::class);
});

function spellingBeeGame(User $user, int $score, int $wordsFound = 0, int $pangramsFound = 0, ?int $maxScore = null, ?string $rank = null): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::SpellingBee, 'mode' => GameMode::Solo]);

    $stats = ['words_found' => $wordsFound, 'pangrams_found' => $pangramsFound];

    if ($maxScore !== null) {
        $stats['max_score'] = $maxScore;
    }

    if ($rank !== null) {
        $stats['rank'] = $rank;
    }

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $score,
        'stats' => $stats,
    ]);
}

test('gamesForUser only returns the given user\'s own Word Hive participants, newest first', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $first = spellingBeeGame($user, 10);
    $second = spellingBeeGame($user, 20);
    spellingBeeGame($otherUser, 30);

    // A non-Word-Hive game for the same user shouldn't show up either.
    $boggleSession = GameSession::factory()->create(['type' => GameType::Boggle]);
    GameParticipant::factory()->create(['game_session_id' => $boggleSession->id, 'user_id' => $user->id]);

    $games = $this->service->gamesForUser($user);

    expect($games->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('statsFor reads the raw stored counters', function () {
    $user = User::factory()->create();
    $participant = spellingBeeGame($user, score: 62, wordsFound: 8, pangramsFound: 1, maxScore: 100, rank: 'Amazing');

    expect($this->service->statsFor($participant))->toBe([
        'wordsFound' => 8,
        'pangramsFound' => 1,
        'maxScore' => 100,
        'rank' => 'Amazing',
    ]);
});

test('statsFor defaults to zero/null for a participant with no stats recorded', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::SpellingBee]);
    $participant = GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => 0,
        'stats' => [],
    ]);

    expect($this->service->statsFor($participant))->toBe([
        'wordsFound' => 0,
        'pangramsFound' => 0,
        'maxScore' => null,
        'rank' => null,
    ]);
});

test('bestMetrics picks the highest-scoring game, and omits pangrams found if no game ever found one', function () {
    $user = User::factory()->create();

    $low = spellingBeeGame($user, score: 4, rank: 'Beginner');
    $high = spellingBeeGame($user, score: 40, wordsFound: 10, rank: 'Great');

    $metrics = $this->service->bestMetrics($user);
    $labels = collect($metrics)->pluck('label')->all();

    expect($labels)->toContain('Highest score')
        ->and($labels)->toContain('Most words found')
        ->and($labels)->not->toContain('Most pangrams found');

    $best = collect($metrics)->firstWhere('label', 'Highest score');
    expect($best['participant']->id)->toBe($high->id)
        ->and($best['value'])->toBe('40');
    expect($low->id)->not->toBe($high->id);
});

test('bestMetrics includes most pangrams found once any game has found one', function () {
    $user = User::factory()->create();
    $game = spellingBeeGame($user, score: 48, pangramsFound: 2, rank: 'Genius');

    $metrics = $this->service->bestMetrics($user);
    $pangramMetric = collect($metrics)->firstWhere('label', 'Most pangrams found');

    expect($pangramMetric['value'])->toBe('2')
        ->and($pangramMetric['participant']->id)->toBe($game->id);
});

test('bestMetrics compares best rank reached by tier position, not raw score or maxScore', function () {
    $user = User::factory()->create();

    // Lower raw score AND lower maxScore, but a higher tier -- "Genius" on
    // an easy puzzle beats "Solid" on a hard one, by tier position alone.
    $easyPuzzleGenius = spellingBeeGame($user, score: 20, maxScore: 25, rank: 'Genius');
    spellingBeeGame($user, score: 90, maxScore: 300, rank: 'Solid');

    $metrics = $this->service->bestMetrics($user);
    $rankMetric = collect($metrics)->firstWhere('label', 'Best rank reached');

    expect($rankMetric['value'])->toBe('Genius')
        ->and($rankMetric['participant']->id)->toBe($easyPuzzleGenius->id);
});

test('bestMetrics omits best rank reached if no game has a recorded rank', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::SpellingBee]);
    GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => 10,
        'stats' => ['words_found' => 3, 'pangrams_found' => 0],
    ]);

    $metrics = $this->service->bestMetrics($user);

    expect(collect($metrics)->pluck('label')->all())->not->toContain('Best rank reached');
});
