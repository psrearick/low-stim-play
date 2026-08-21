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

function participantFor(User $user, GameType $type, int $score, ?GameResult $result, GameMode $mode = GameMode::Solo): GameParticipant
{
    $session = GameSession::factory()->create(['type' => $type, 'mode' => $mode]);

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

    participantFor($user, GameType::Boggle, 100, GameResult::Win, GameMode::Online);
    participantFor($user, GameType::Boggle, 50, GameResult::Loss, GameMode::Online);
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

/**
 * Boggle-specific win-rate scoping (docs/TODO.md's former #1): solo play
 * has no opponent, so its GameParticipant.result is always null -- it can
 * never land in the win-rate numerator, but was still inflating the
 * denominator just by existing. Yahtzee/Family Feud never have this
 * problem (they always compute a real result, even for local pass-and-
 * play), so this scoping is Boggle-only and the tests above stay
 * unaffected.
 */
test('solo Boggle games count toward games played and avg score but never toward win rate', function () {
    $user = User::factory()->create();

    participantFor($user, GameType::Boggle, 40, null, GameMode::Solo);
    participantFor($user, GameType::Boggle, 60, null, GameMode::Solo);

    $stats = $this->service->forUser($user)->first();

    expect($stats['gamesPlayed'])->toBe(2)
        ->and($stats['avgScore'])->toBe(50.0)
        ->and($stats['winRate'])->toBe(0);
});

test('an online Boggle session where every participant scored zero is excluded from win rate entirely', function () {
    $user = User::factory()->create();

    // A real, played game this user won -- gives the win-rate denominator
    // something nonzero to distinguish "excluded" from "included as a loss".
    $wonSession = GameSession::factory()->create(['type' => GameType::Boggle, 'mode' => GameMode::Online]);
    GameParticipant::factory()->create([
        'game_session_id' => $wonSession->id, 'user_id' => $user->id, 'score' => 10, 'result' => GameResult::Win,
    ]);

    // An aborted room nobody actually played -- stored as a Loss (matching
    // how SharedSessionFinalizer would score it), but with nothing to
    // distinguish it from a room that never really started.
    $abortedSession = GameSession::factory()->create(['type' => GameType::Boggle, 'mode' => GameMode::Online]);
    GameParticipant::factory()->create([
        'game_session_id' => $abortedSession->id, 'user_id' => $user->id, 'score' => 0, 'result' => GameResult::Loss,
    ]);

    $stats = $this->service->forUser($user)->first();

    // Both still count as "games played" -- only the win-rate calculation
    // excludes the aborted one. If it were wrongly included, this would
    // be 50 (1 win / 2 games) instead of 100 (1 win / 1 eligible game).
    expect($stats['gamesPlayed'])->toBe(2)
        ->and($stats['winRate'])->toBe(100);
});

test('a session the user personally scored zero in still counts as a real loss if someone else in it scored above zero', function () {
    $user = User::factory()->create();
    $rival = User::factory()->create();

    $wonSession = GameSession::factory()->create(['type' => GameType::Boggle, 'mode' => GameMode::Online]);
    GameParticipant::factory()->create([
        'game_session_id' => $wonSession->id, 'user_id' => $user->id, 'score' => 10, 'result' => GameResult::Win,
    ]);

    $lostSession = GameSession::factory()->create(['type' => GameType::Boggle, 'mode' => GameMode::Online]);
    GameParticipant::factory()->create([
        'game_session_id' => $lostSession->id, 'user_id' => $user->id, 'score' => 0, 'result' => GameResult::Loss,
    ]);
    GameParticipant::factory()->create([
        'game_session_id' => $lostSession->id, 'user_id' => $rival->id, 'score' => 5, 'result' => GameResult::Win,
    ]);

    $stats = $this->service->forUser($user)->first();

    // If the zero-for-*this-user* session were wrongly excluded, this
    // would be 100 (1 win / 1 game) instead of 50 (1 win / 2 games).
    expect($stats['gamesPlayed'])->toBe(2)
        ->and($stats['wins'])->toBe(1)
        ->and($stats['winRate'])->toBe(50);
});

test('a solo Boggle game is excluded from win rate even if it somehow has a non-null result', function () {
    $user = User::factory()->create();

    participantFor($user, GameType::Boggle, 100, GameResult::Win, GameMode::Solo);

    $stats = $this->service->forUser($user)->first();

    expect($stats['gamesPlayed'])->toBe(1)
        ->and($stats['winRate'])->toBe(0);
});

/**
 * docs/TODO.md's Task 12: Boggle's room score (an "exclusive" score, see
 * SharedSessionFinalizer) and solo score (a raw point total) are different
 * scales -- blending them into one avgScore skews it, so they're also
 * exposed split by mode. avgScore itself is left computed exactly as
 * before, for any other consumer that still wants the single number.
 * Each mode's own game count is returned alongside its average rather
 * than the average being null when unplayed -- see PlayerStatsService::
 * avgScoreByMode()'s own docblock for why every field here is
 * deliberately non-nullable (a confirmed Larastan false positive, not a
 * design preference).
 */
test('avgScoreByMode splits Boggle\'s average score into solo and online, leaving avgScore blended as before', function () {
    $user = User::factory()->create();

    participantFor($user, GameType::Boggle, 40, null, GameMode::Solo);
    participantFor($user, GameType::Boggle, 60, null, GameMode::Solo);
    participantFor($user, GameType::Boggle, 100, GameResult::Win, GameMode::Online);

    $stats = $this->service->forUser($user)->first();

    expect($stats['avgScore'])->toBe(66.7)
        ->and($stats['avgScoreByMode']['soloGames'])->toBe(2)
        ->and($stats['avgScoreByMode']['soloAvg'])->toBe(50.0)
        ->and($stats['avgScoreByMode']['onlineGames'])->toBe(1)
        ->and($stats['avgScoreByMode']['onlineAvg'])->toBe(100.0);
});

test('avgScoreByMode reports a zero game count for a mode Boggle hasn\'t been played in yet', function () {
    $user = User::factory()->create();

    participantFor($user, GameType::Boggle, 40, null, GameMode::Solo);

    $stats = $this->service->forUser($user)->first();

    expect($stats['avgScoreByMode']['soloGames'])->toBe(1)
        ->and($stats['avgScoreByMode']['soloAvg'])->toBe(40.0)
        ->and($stats['avgScoreByMode']['onlineGames'])->toBe(0)
        ->and($stats['avgScoreByMode']['onlineAvg'])->toBe(0.0);
});

test('avgScoreByMode is computed but ignored by the UI for non-Boggle game types', function () {
    $user = User::factory()->create();

    participantFor($user, GameType::Yahtzee, 200, GameResult::Win);

    $stats = $this->service->forUser($user)->first();

    expect($stats['avgScoreByMode']['soloGames'])->toBe(1)
        ->and($stats['avgScoreByMode']['onlineGames'])->toBe(0);
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
