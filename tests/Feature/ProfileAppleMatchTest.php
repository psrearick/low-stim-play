<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function appleMatchParticipantFor(User $user, int $greenCardsWon = 4, int $roundsJudged = 1): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::AppleMatch, 'mode' => GameMode::Online]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $greenCardsWon,
        'stats' => ['green_cards_won' => $greenCardsWon, 'rounds_judged' => $roundsJudged],
    ]);
}

test('guests are redirected to login for the best match stats index', function () {
    $this->get('/profile/apple-match')->assertRedirect('/login');
});

test('guests are redirected to login for a best match game detail page', function () {
    $user = User::factory()->create();
    $participant = appleMatchParticipantFor($user);

    $this->get("/profile/apple-match/{$participant->id}")->assertRedirect('/login');
});

test('the best match stats index renders for an authenticated user', function () {
    $user = User::factory()->create();
    appleMatchParticipantFor($user, 5);

    $this->actingAs($user)
        ->get('/profile/apple-match')
        ->assertSeeLivewire('pages::profile.apple-match.index')
        ->assertSee('5');
});

test('the best match stats index only shows the current user\'s own games', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    appleMatchParticipantFor($user, 3);
    $otherGame = appleMatchParticipantFor($otherUser, 6);

    Livewire::actingAs($user)
        ->test('pages::profile.apple-match.index')
        ->assertCount('games', 1)
        ->assertDontSee(route('profile.apple-match.show', $otherGame), escape: false);
});

test('a best match game detail page shows its score, result, and rounds judged', function () {
    $user = User::factory()->create();
    $participant = appleMatchParticipantFor($user, greenCardsWon: 4, roundsJudged: 2);

    $this->actingAs($user)
        ->get("/profile/apple-match/{$participant->id}")
        ->assertSeeLivewire('pages::profile.apple-match.show')
        ->assertSee('4')
        ->assertSee('2');
});

test('a user cannot view another user\'s best match game detail page', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $participant = appleMatchParticipantFor($otherUser);

    $this->actingAs($user)
        ->get("/profile/apple-match/{$participant->id}")
        ->assertNotFound();
});

test('a best match game detail page 404s for a non-Best-Match participant', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::Boggle]);
    $participant = GameParticipant::factory()->create(['game_session_id' => $session->id, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->get("/profile/apple-match/{$participant->id}")
        ->assertNotFound();
});

test('the dashboard links the best match tile to the best match stats page once linkable', function () {
    $user = User::factory()->create(['username' => 'match-fan']);
    appleMatchParticipantFor($user);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSee(route('profile.apple-match'), escape: false);
});
