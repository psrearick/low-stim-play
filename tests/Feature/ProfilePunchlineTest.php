<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function punchlineParticipantFor(User $user, int $score = 3600, array $pointsByRound = [1000, 1200, 1400], int $sweepsWon = 1): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::Punchline, 'mode' => GameMode::Online]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $score,
        'stats' => ['pointsByRound' => $pointsByRound, 'sweepsWon' => $sweepsWon],
    ]);
}

test('guests are redirected to login for the punchline stats index', function () {
    $this->get('/profile/punchline')->assertRedirect('/login');
});

test('guests are redirected to login for a punchline game detail page', function () {
    $user = User::factory()->create();
    $participant = punchlineParticipantFor($user);

    $this->get("/profile/punchline/{$participant->id}")->assertRedirect('/login');
});

test('the punchline stats index renders for an authenticated user', function () {
    $user = User::factory()->create();
    punchlineParticipantFor($user, 5000);

    $this->actingAs($user)
        ->get('/profile/punchline')
        ->assertSeeLivewire('pages::profile.punchline.index')
        ->assertSee('5000');
});

test('the punchline stats index only shows the current user\'s own games', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    punchlineParticipantFor($user, 3000);
    $otherGame = punchlineParticipantFor($otherUser, 6000);

    Livewire::actingAs($user)
        ->test('pages::profile.punchline.index')
        ->assertCount('games', 1)
        ->assertDontSee(route('profile.punchline.show', $otherGame), escape: false);
});

test('a punchline game detail page shows its score, result, and per-round points', function () {
    $user = User::factory()->create();
    $participant = punchlineParticipantFor($user, score: 3600, pointsByRound: [1000, 1200, 1400]);

    $this->actingAs($user)
        ->get("/profile/punchline/{$participant->id}")
        ->assertSeeLivewire('pages::profile.punchline.show')
        ->assertSee('3600')
        ->assertSee('1000')
        ->assertSee('1200')
        ->assertSee('1400');
});

test('a user cannot view another user\'s punchline game detail page', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $participant = punchlineParticipantFor($otherUser);

    $this->actingAs($user)
        ->get("/profile/punchline/{$participant->id}")
        ->assertNotFound();
});

test('a punchline game detail page 404s for a non-Punchline participant', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::Boggle]);
    $participant = GameParticipant::factory()->create(['game_session_id' => $session->id, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->get("/profile/punchline/{$participant->id}")
        ->assertNotFound();
});

test('the dashboard links the punchline tile to the punchline stats page once linkable', function () {
    $user = User::factory()->create(['username' => 'punchline-fan']);
    punchlineParticipantFor($user);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSee(route('profile.punchline'), escape: false);
});
