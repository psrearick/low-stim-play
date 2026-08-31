<?php

use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function pipsParticipantFor(User $user, string $difficulty = 'easy', bool $solved = true, ?int $durationSeconds = 60, int $score = 970): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::Pips]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $score,
        'stats' => ['solved' => $solved, 'duration_seconds' => $durationSeconds, 'difficulty' => $difficulty],
    ]);
}

test('guests are redirected to login for the pips stats index', function () {
    $this->get('/profile/pips')->assertRedirect('/login');
});

test('guests are redirected to login for a pips game detail page', function () {
    $user = User::factory()->create();
    $participant = pipsParticipantFor($user);

    $this->get("/profile/pips/{$participant->id}")->assertRedirect('/login');
});

test('the pips stats index renders for an authenticated user', function () {
    $user = User::factory()->create();
    pipsParticipantFor($user);

    $this->actingAs($user)
        ->get('/profile/pips')
        ->assertSeeLivewire('pages::profile.pips.index');
});

test('the pips stats index only shows the current user\'s own games', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    pipsParticipantFor($user);
    pipsParticipantFor($otherUser);

    Livewire::actingAs($user)
        ->test('pages::profile.pips.index')
        ->assertCount('games', 1);
});

test('the pips stats index shows separate personal-bests sections per difficulty, each linking to its own best game', function () {
    $user = User::factory()->create();
    $easyBest = pipsParticipantFor($user, 'easy', true, 50, 975);
    $hardBest = pipsParticipantFor($user, 'hard', true, 500, 750);

    $response = $this->actingAs($user)->get('/profile/pips');

    $response->assertSee('Personal bests (Easy)')
        ->assertSee('Personal bests (Hard)')
        ->assertSee(route('profile.pips.show', $easyBest), escape: false)
        ->assertSee(route('profile.pips.show', $hardBest), escape: false);
});

test('the pips stats index omits a difficulty\'s personal-bests section when the user has no games at that tier', function () {
    $user = User::factory()->create();
    pipsParticipantFor($user, 'easy');

    $this->actingAs($user)
        ->get('/profile/pips')
        ->assertSee('Personal bests (Easy)')
        ->assertDontSee('Personal bests (Medium)')
        ->assertDontSee('Personal bests (Hard)');
});

test('a pips game detail page shows its stats', function () {
    $user = User::factory()->create();
    $participant = pipsParticipantFor($user, 'hard', true, 125, 937);

    $this->actingAs($user)
        ->get("/profile/pips/{$participant->id}")
        ->assertSeeLivewire('pages::profile.pips.show')
        ->assertSee('Hard')
        ->assertSee('Solved')
        ->assertSee('937')
        ->assertSee('2m 5s');
});

test('a pips game detail page shows Abandoned for an unsolved game with no solve time tile', function () {
    $user = User::factory()->create();
    $participant = pipsParticipantFor($user, 'easy', false, null, 0);

    $this->actingAs($user)
        ->get("/profile/pips/{$participant->id}")
        ->assertSee('Abandoned')
        ->assertDontSee('Solve time');
});

test('a user cannot view another user\'s pips game detail page', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $participant = pipsParticipantFor($otherUser);

    $this->actingAs($user)
        ->get("/profile/pips/{$participant->id}")
        ->assertNotFound();
});

test('a pips game detail page 404s for a non-Pips participant', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::Yahtzee]);
    $participant = GameParticipant::factory()->create(['game_session_id' => $session->id, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->get("/profile/pips/{$participant->id}")
        ->assertNotFound();
});
