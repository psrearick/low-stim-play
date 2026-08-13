<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function clearTheDeckParticipantFor(User $user, int $finalScore = 8, int $roundsWon = 1): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::ClearTheDeck, 'mode' => GameMode::Online]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $finalScore,
        'stats' => ['rounds_won' => $roundsWon],
    ]);
}

test('guests are redirected to login for the clear the deck stats index', function () {
    $this->get('/profile/clear-the-deck')->assertRedirect('/login');
});

test('guests are redirected to login for a clear the deck game detail page', function () {
    $user = User::factory()->create();
    $participant = clearTheDeckParticipantFor($user);

    $this->get("/profile/clear-the-deck/{$participant->id}")->assertRedirect('/login');
});

test('the clear the deck stats index renders for an authenticated user', function () {
    $user = User::factory()->create();
    clearTheDeckParticipantFor($user, 5);

    $this->actingAs($user)
        ->get('/profile/clear-the-deck')
        ->assertSeeLivewire('pages::profile.clear-the-deck.index')
        ->assertSee('5');
});

test('the clear the deck stats index only shows the current user\'s own games', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    clearTheDeckParticipantFor($user, 3);
    $otherGame = clearTheDeckParticipantFor($otherUser, 6);

    Livewire::actingAs($user)
        ->test('pages::profile.clear-the-deck.index')
        ->assertCount('games', 1)
        ->assertDontSee(route('profile.clear-the-deck.show', $otherGame), escape: false);
});

test('a clear the deck game detail page shows its final score, result, and rounds won', function () {
    $user = User::factory()->create();
    $participant = clearTheDeckParticipantFor($user, finalScore: 7, roundsWon: 2);

    $this->actingAs($user)
        ->get("/profile/clear-the-deck/{$participant->id}")
        ->assertSeeLivewire('pages::profile.clear-the-deck.show')
        ->assertSee('7')
        ->assertSee('2');
});

test('a user cannot view another user\'s clear the deck game detail page', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $participant = clearTheDeckParticipantFor($otherUser);

    $this->actingAs($user)
        ->get("/profile/clear-the-deck/{$participant->id}")
        ->assertNotFound();
});

test('a clear the deck game detail page 404s for a non-Clear-the-Deck participant', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::Boggle]);
    $participant = GameParticipant::factory()->create(['game_session_id' => $session->id, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->get("/profile/clear-the-deck/{$participant->id}")
        ->assertNotFound();
});

test('the dashboard links the clear the deck tile to the clear the deck stats page once linkable', function () {
    $user = User::factory()->create(['username' => 'deck-fan']);
    clearTheDeckParticipantFor($user);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSee(route('profile.clear-the-deck'), escape: false);
});
