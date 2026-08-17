<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @param  array<string, int|null>  $scores
 */
function yahtzeeParticipantFor(User $user, array $scores = ['ones' => 3], int $score = 3, int $yahtzeeBonusCount = 0): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::Yahtzee, 'mode' => GameMode::Local]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $score,
        'stats' => ['scores' => $scores, 'yahtzee_bonus_count' => $yahtzeeBonusCount],
    ]);
}

test('guests are redirected to login for the yahtzee stats index', function () {
    $this->get('/profile/yacht')->assertRedirect('/login');
});

test('guests are redirected to login for a yahtzee game detail page', function () {
    $user = User::factory()->create();
    $participant = yahtzeeParticipantFor($user);

    $this->get("/profile/yacht/{$participant->id}")->assertRedirect('/login');
});

test('the yahtzee stats index renders for an authenticated user', function () {
    $user = User::factory()->create();
    yahtzeeParticipantFor($user, ['sixes' => 24], 24);

    $this->actingAs($user)
        ->get('/profile/yacht')
        ->assertSeeLivewire('pages::profile.yahtzee.index')
        ->assertSee('24');
});

test('the yahtzee stats index only shows the current user\'s own games', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    yahtzeeParticipantFor($user, ['ones' => 3], 3);
    $otherGame = yahtzeeParticipantFor($otherUser, ['sixes' => 30], 30);

    // Not assertDontSee('30') -- Livewire's own randomly-generated
    // wire:id can coincidentally contain any given short numeric
    // substring (confirmed live: this flaked on a wire:id containing
    // "30" purely by chance), making a bare 1-2 digit assertion
    // fundamentally unreliable here regardless of the real behavior
    // under test. The other user's specific show-page link is a far
    // more specific, collision-resistant thing to assert is absent.
    Livewire::actingAs($user)
        ->test('pages::profile.yahtzee.index')
        ->assertCount('games', 1)
        ->assertDontSee(route('profile.yahtzee.show', $otherGame), escape: false);
});

test('the yahtzee stats index links each personal best to the game it came from', function () {
    $user = User::factory()->create();
    $best = yahtzeeParticipantFor($user, ['sixes' => 24], 24);

    $this->actingAs($user)
        ->get('/profile/yacht')
        ->assertSee(route('profile.yahtzee.show', $best), escape: false);
});

test('a yahtzee game detail page shows its scorecard and headline stats', function () {
    $user = User::factory()->create();
    $participant = yahtzeeParticipantFor($user, [
        'ones' => 3, 'twos' => 6, 'threes' => 9, 'fours' => 12, 'fives' => 15, 'sixes' => 18,
        'yahtzee' => 50,
    ], score: 148, yahtzeeBonusCount: 1);

    $this->actingAs($user)
        ->get("/profile/yacht/{$participant->id}")
        ->assertSeeLivewire('pages::profile.yahtzee.show')
        ->assertSee('148')
        ->assertSee('Yes'); // upper bonus achieved (63 exactly)
});

test('a user cannot view another user\'s yahtzee game detail page', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $participant = yahtzeeParticipantFor($otherUser);

    $this->actingAs($user)
        ->get("/profile/yacht/{$participant->id}")
        ->assertNotFound();
});

test('a yahtzee game detail page 404s for a non-Yahtzee participant', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::Boggle]);
    $participant = GameParticipant::factory()->create(['game_session_id' => $session->id, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->get("/profile/yacht/{$participant->id}")
        ->assertNotFound();
});

test('the dashboard links the yahtzee tile to the yahtzee stats page once linkable', function () {
    $user = User::factory()->create(['username' => 'yahtzee-fan']);
    yahtzeeParticipantFor($user);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSee(route('profile.yahtzee'), escape: false);
});
