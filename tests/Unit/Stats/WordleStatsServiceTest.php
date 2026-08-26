<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use App\Services\Stats\WordleStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(WordleStatsService::class);
});

function wordleGame(User $user, int $score, bool $won = false, ?int $guessCount = null, bool $hardMode = false): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::Wordle, 'mode' => GameMode::Solo]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $score,
        'stats' => ['won' => $won, 'guess_count' => $guessCount, 'hard_mode' => $hardMode],
    ]);
}

test('gamesForUser only returns the given user\'s own Five Letters participants, newest first', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $first = wordleGame($user, 100);
    $second = wordleGame($user, 200);
    wordleGame($otherUser, 300);

    // A non-Wordle game for the same user shouldn't show up either.
    $boggleSession = GameSession::factory()->create(['type' => GameType::Boggle]);
    GameParticipant::factory()->create(['game_session_id' => $boggleSession->id, 'user_id' => $user->id]);

    $games = $this->service->gamesForUser($user);

    expect($games->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('statsFor reads the raw stored counters', function () {
    $user = User::factory()->create();
    $participant = wordleGame($user, score: 500, won: true, guessCount: 2, hardMode: true);

    expect($this->service->statsFor($participant))->toBe([
        'won' => true,
        'guessCount' => 2,
        'hardMode' => true,
    ]);
});

test('statsFor defaults to false/null for a participant with no stats recorded', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::Wordle]);
    $participant = GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => 0,
        'stats' => [],
    ]);

    expect($this->service->statsFor($participant))->toBe([
        'won' => false,
        'guessCount' => null,
        'hardMode' => false,
    ]);
});

test('bestMetrics returns nothing at all if the user has never won', function () {
    // TODO Task 21: no score-based fallback tile any more -- a
    // score-based "Highest score" would either repeat "Fewest guesses to
    // win" for a winner, or show a meaningless sentinel for someone who's
    // never won, so it was dropped entirely rather than kept and relabeled.
    $user = User::factory()->create();

    wordleGame($user, score: 7, won: false);

    expect($this->service->bestMetrics($user))->toBe([]);
});

test('bestMetrics includes fewest guesses to win once any game has been won, and nothing else', function () {
    $user = User::factory()->create();

    wordleGame($user, score: 5, won: true, guessCount: 5);
    $best = wordleGame($user, score: 1, won: true, guessCount: 1);

    $metrics = $this->service->bestMetrics($user);

    expect($metrics)->toHaveCount(1);

    $fewest = collect($metrics)->firstWhere('label', 'Fewest guesses to win');

    expect($fewest['value'])->toBe('1')
        ->and($fewest['participant']->id)->toBe($best->id);
});
