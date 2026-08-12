<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function farkleParticipantFor(User $user, int $score = 500, int $farkleCount = 0, int $hotDiceCount = 0, int $bestTurnScore = 0): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::Farkle, 'mode' => GameMode::Local]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $score,
        'stats' => [
            'farkle_count' => $farkleCount,
            'hot_dice_count' => $hotDiceCount,
            'best_turn_score' => $bestTurnScore,
        ],
    ]);
}

test('guests are redirected to login for the farkle stats index', function () {
    $this->get('/profile/10000')->assertRedirect('/login');
});

test('guests are redirected to login for a farkle game detail page', function () {
    $user = User::factory()->create();
    $participant = farkleParticipantFor($user);

    $this->get("/profile/10000/{$participant->id}")->assertRedirect('/login');
});

test('the farkle stats index renders for an authenticated user', function () {
    $user = User::factory()->create();
    farkleParticipantFor($user, score: 2400);

    $this->actingAs($user)
        ->get('/profile/10000')
        ->assertSeeLivewire('pages::profile.farkle.index')
        ->assertSee('2400');
});

test('the farkle stats index only shows the current user\'s own games', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    farkleParticipantFor($user);
    $otherGame = farkleParticipantFor($otherUser, score: 3000);

    Livewire::actingAs($user)
        ->test('pages::profile.farkle.index')
        ->assertCount('games', 1)
        ->assertDontSee(route('profile.farkle.show', $otherGame), escape: false);
});

test('the farkle stats index links the highest-score personal best to the game it came from', function () {
    $user = User::factory()->create();
    $best = farkleParticipantFor($user, score: 5000);

    $this->actingAs($user)
        ->get('/profile/10000')
        ->assertSee(route('profile.farkle.show', $best), escape: false);
});

test('a farkle game detail page shows its headline stats', function () {
    $user = User::factory()->create();
    $participant = farkleParticipantFor($user, score: 6200, farkleCount: 2, hotDiceCount: 1, bestTurnScore: 3200);

    $this->actingAs($user)
        ->get("/profile/10000/{$participant->id}")
        ->assertSeeLivewire('pages::profile.farkle.show')
        ->assertSee('6200')
        ->assertSee('3200')
        ->assertSee('2') // farkle count
        ->assertSee('1'); // hot dice count
});

test('a user cannot view another user\'s farkle game detail page', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $participant = farkleParticipantFor($otherUser);

    $this->actingAs($user)
        ->get("/profile/10000/{$participant->id}")
        ->assertNotFound();
});

test('a farkle game detail page 404s for a non-Farkle participant', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::Boggle]);
    $participant = GameParticipant::factory()->create(['game_session_id' => $session->id, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->get("/profile/10000/{$participant->id}")
        ->assertNotFound();
});

test('the dashboard links the farkle tile to the farkle stats page once linkable', function () {
    $user = User::factory()->create(['username' => 'farkle-fan']);
    farkleParticipantFor($user);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSee(route('profile.farkle'), escape: false);
});
