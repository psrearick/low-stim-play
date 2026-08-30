<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use App\Services\Stats\BoggleStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = new BoggleStatsService;
});

/**
 * @param  array<string, int>  $foundWords
 */
function boggleGame(User $user, array $foundWords, int $score, ?int $totalValidWords = null): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::Boggle, 'mode' => GameMode::Solo]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $score,
        'stats' => array_filter([
            'words_found' => count($foundWords),
            'found_words' => $foundWords,
            'total_valid_words' => $totalValidWords,
        ], fn ($value) => $value !== null),
    ]);
}

test('gamesForUser only returns the given user\'s own Boggle participants, newest first', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $first = boggleGame($user, ['cat' => 1], 1);
    $second = boggleGame($user, ['dog' => 1], 1);
    boggleGame($otherUser, ['rat' => 1], 1);

    // A non-Boggle game for the same user shouldn't show up either.
    $yahtzeeSession = GameSession::factory()->create(['type' => GameType::Yahtzee]);
    GameParticipant::factory()->create(['game_session_id' => $yahtzeeSession->id, 'user_id' => $user->id]);

    $games = $this->service->gamesForUser($user);

    expect($games->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('statsFor computes words found, longest word, and per-length breakdown from found_words', function () {
    $user = User::factory()->create();
    $participant = boggleGame($user, ['cat' => 1, 'plate' => 2, 'dog' => 1], 4, totalValidWords: 20);

    $stats = $this->service->statsFor($participant);

    expect($stats['wordsFound'])->toBe(3)
        ->and($stats['longestWord'])->toBe('plate')
        ->and($stats['longestWordLength'])->toBe(5)
        ->and($stats['byLength'])->toBe([3 => 2, 5 => 1])
        ->and($stats['totalValidWords'])->toBe(20)
        ->and($stats['percentFound'])->toBe(15.0);
});

test('statsFor handles a game with no words found and no recorded board size', function () {
    $user = User::factory()->create();
    $participant = boggleGame($user, [], 0);

    $stats = $this->service->statsFor($participant);

    expect($stats['wordsFound'])->toBe(0)
        ->and($stats['longestWord'])->toBeNull()
        ->and($stats['byLength'])->toBe([])
        ->and($stats['totalValidWords'])->toBeNull()
        ->and($stats['percentFound'])->toBeNull()
        ->and($stats['longWordPoints'])->toBe(0);
});

test('statsFor sums points only from words over 4 letters long', function () {
    $user = User::factory()->create();
    // cat/dog (3) and plus (4) are excluded; plate (5) and dragon (6) count.
    $participant = boggleGame($user, ['cat' => 1, 'dog' => 1, 'plus' => 1, 'plate' => 2, 'dragon' => 3], 8);

    $stats = $this->service->statsFor($participant);

    expect($stats['longWordPoints'])->toBe(5);
});

test('bestMetrics picks the game with the highest value for each metric', function () {
    $user = User::factory()->create();

    $highScore = boggleGame($user, ['cat' => 1], 50);
    $mostWords = boggleGame($user, ['cat' => 1, 'dog' => 1, 'rat' => 1], 3);
    $longest = boggleGame($user, ['elephant' => 11], 11);
    $bestPercent = boggleGame($user, ['cat' => 1, 'dog' => 1], 2, totalValidWords: 4);

    $metrics = collect($this->service->bestMetrics($user))->keyBy('label');

    expect($metrics['Highest score']['participant']->id)->toBe($highScore->id)
        ->and($metrics['Most words found']['participant']->id)->toBe($mostWords->id)
        ->and($metrics['Longest word']['value'])->toBe('ELEPHANT')
        ->and($metrics['Longest word']['participant']->id)->toBe($longest->id)
        ->and($metrics['Most of the board found']['value'])->toBe('50%')
        ->and($metrics['Most of the board found']['participant']->id)->toBe($bestPercent->id)
        ->and($metrics['Most points from long words (5+ letters)']['value'])->toBe('11')
        ->and($metrics['Most points from long words (5+ letters)']['participant']->id)->toBe($longest->id);
});

test('bestMetrics omits the percent-found metric entirely when no game has a recorded board size', function () {
    $user = User::factory()->create();
    boggleGame($user, ['cat' => 1], 1);

    $labels = collect($this->service->bestMetrics($user))->pluck('label');

    expect($labels)->not->toContain('Most of the board found');
});

test('bestMetrics is empty for a user with no Boggle games', function () {
    $user = User::factory()->create();

    expect($this->service->bestMetrics($user))->toBe([]);
});

test('averagePercentFound averages each game\'s own percentFound, ignoring games with no recorded board size', function () {
    $user = User::factory()->create();

    boggleGame($user, ['cat' => 1, 'dog' => 1], 2, totalValidWords: 4); // 50%
    boggleGame($user, ['cat' => 1], 1, totalValidWords: 10); // 10%
    boggleGame($user, ['cat' => 1], 1); // no total_valid_words -- excluded

    expect($this->service->averagePercentFound($user))->toBe(30.0);
});

test('averagePercentFound is null when no game has a recorded board size', function () {
    $user = User::factory()->create();
    boggleGame($user, ['cat' => 1], 1);

    expect($this->service->averagePercentFound($user))->toBeNull();
});

test('averagePercentFound is null for a user with no Boggle games', function () {
    $user = User::factory()->create();

    expect($this->service->averagePercentFound($user))->toBeNull();
});

test('topLongestWords returns distinct words sorted by length, tied lengths alphabetical', function () {
    $user = User::factory()->create();

    boggleGame($user, ['cat' => 1, 'zebra' => 2, 'apple' => 2], 5);
    boggleGame($user, ['cat' => 1], 1); // duplicate "cat", already counted

    $words = collect($this->service->topLongestWords($user))->pluck('word')->all();

    expect($words)->toBe(['apple', 'zebra', 'cat']);
});

test('topLongestWords respects the limit', function () {
    $user = User::factory()->create();
    boggleGame($user, ['aaa' => 1, 'bbbb' => 1, 'ccccc' => 1, 'ddddd' => 1], 4);

    expect($this->service->topLongestWords($user, limit: 2))->toHaveCount(2);
});

test('topFrequentWords counts how many separate games each word was found in', function () {
    $user = User::factory()->create();

    boggleGame($user, ['cat' => 1, 'dog' => 1], 2);
    boggleGame($user, ['cat' => 1], 1);
    boggleGame($user, ['cat' => 1], 1);

    $frequent = collect($this->service->topFrequentWords($user))->keyBy('word');

    expect($frequent['cat']['count'])->toBe(3)
        ->and($frequent['dog']['count'])->toBe(1);
});
