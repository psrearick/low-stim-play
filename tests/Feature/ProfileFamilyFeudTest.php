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
 * @param  list<int>  $roundHistory
 */
function familyFeudParticipantFor(User $user, array $roundHistory = [10], int $score = 10, int $teamIndex = 0, bool $isCaptain = false): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::FamilyFeud, 'mode' => GameMode::Local]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $score,
        'stats' => ['team_index' => $teamIndex, 'is_captain' => $isCaptain, 'round_history' => $roundHistory],
    ]);
}

test('guests are redirected to login for the family feud stats index', function () {
    $this->get('/profile/family-feud')->assertRedirect('/login');
});

test('guests are redirected to login for a family feud game detail page', function () {
    $user = User::factory()->create();
    $participant = familyFeudParticipantFor($user);

    $this->get("/profile/family-feud/{$participant->id}")->assertRedirect('/login');
});

test('the family feud stats index renders for an authenticated user', function () {
    $user = User::factory()->create();
    familyFeudParticipantFor($user, [40, 30], 70);

    $this->actingAs($user)
        ->get('/profile/family-feud')
        ->assertSeeLivewire('pages::profile.family-feud.index')
        ->assertSee('70');
});

test('the family feud stats index only shows the current user\'s own games', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    familyFeudParticipantFor($user, [10], 10);
    $otherGame = familyFeudParticipantFor($otherUser, [30], 30);

    // Not assertDontSee('30') -- see ProfileYahtzeeTest's identical note:
    // Livewire's own randomly-generated wire:id can coincidentally contain
    // any given short numeric substring.
    Livewire::actingAs($user)
        ->test('pages::profile.family-feud.index')
        ->assertCount('games', 1)
        ->assertDontSee(route('profile.family-feud.show', $otherGame), escape: false);
});

test('the family feud stats index links each personal best to the game it came from', function () {
    $user = User::factory()->create();
    $best = familyFeudParticipantFor($user, [40], 40);

    $this->actingAs($user)
        ->get('/profile/family-feud')
        ->assertSee(route('profile.family-feud.show', $best), escape: false);
});

test('a family feud game detail page shows its score, result, and round-by-round breakdown', function () {
    $user = User::factory()->create();
    $participant = familyFeudParticipantFor($user, [40, 30, 0], score: 70, teamIndex: 1, isCaptain: true);

    $this->actingAs($user)
        ->get("/profile/family-feud/{$participant->id}")
        ->assertSeeLivewire('pages::profile.family-feud.show')
        ->assertSee('70')
        ->assertSee('Captain');
});

test('a user cannot view another user\'s family feud game detail page', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $participant = familyFeudParticipantFor($otherUser);

    $this->actingAs($user)
        ->get("/profile/family-feud/{$participant->id}")
        ->assertNotFound();
});

test('a family feud game detail page 404s for a non-Family-Feud participant', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::Boggle]);
    $participant = GameParticipant::factory()->create(['game_session_id' => $session->id, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->get("/profile/family-feud/{$participant->id}")
        ->assertNotFound();
});

test('the dashboard links the family feud tile to the family feud stats page once linkable', function () {
    $user = User::factory()->create(['username' => 'feud-fan']);
    familyFeudParticipantFor($user);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSee(route('profile.family-feud'), escape: false);
});
