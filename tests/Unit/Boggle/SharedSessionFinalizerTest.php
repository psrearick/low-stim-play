<?php

use App\Enums\GameResult;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Services\Boggle\SharedSessionFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->finalizer = new SharedSessionFinalizer;
});

/**
 * @param  array<string, int>  $foundWords
 */
function participant(GameSession $session, array $foundWords): GameParticipant
{
    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'stats' => ['found_words' => $foundWords],
    ]);
}

test('a word found by only one participant scores for them', function () {
    $session = GameSession::factory()->create(['type' => GameType::Boggle]);
    $me = participant($session, ['cat' => 1, 'dinosaurs' => 11]);
    $rival = participant($session, ['dog' => 1]);

    $this->finalizer->finalize($session);

    expect($me->fresh()->score)->toBe(12)
        ->and($rival->fresh()->score)->toBe(1);
});

test('a word found by more than one participant scores for no one', function () {
    $session = GameSession::factory()->create(['type' => GameType::Boggle]);
    $me = participant($session, ['cat' => 1, 'dog' => 1]);
    $rival = participant($session, ['dog' => 1, 'rat' => 1]);

    $this->finalizer->finalize($session);

    expect($me->fresh()->score)->toBe(1)
        ->and($rival->fresh()->score)->toBe(1);
});

test('the sole top scorer wins and everyone else loses', function () {
    $session = GameSession::factory()->create(['type' => GameType::Boggle]);
    $winner = participant($session, ['dinosaurs' => 11]);
    $loser = participant($session, ['cat' => 1]);

    $this->finalizer->finalize($session);

    expect($winner->fresh()->result)->toBe(GameResult::Win)
        ->and($loser->fresh()->result)->toBe(GameResult::Loss);
});

test('a tied top score is a draw for everyone tied', function () {
    $session = GameSession::factory()->create(['type' => GameType::Boggle]);
    $tiedA = participant($session, ['cat' => 1]);
    $tiedB = participant($session, ['bat' => 1]);
    $behind = participant($session, []);

    $this->finalizer->finalize($session);

    expect($tiedA->fresh()->result)->toBe(GameResult::Draw)
        ->and($tiedB->fresh()->result)->toBe(GameResult::Draw)
        ->and($behind->fresh()->result)->toBe(GameResult::Loss);
});
