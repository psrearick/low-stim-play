<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function quartilesParticipantFor(User $user, int $score = 20, int $wordsFound = 0, int $quartilesFound = 0): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::Quartiles, 'mode' => GameMode::Solo]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $score,
        'stats' => [
            'words_found' => $wordsFound,
            'quartiles_found' => $quartilesFound,
            'found_words' => [],
        ],
    ]);
}

test('guests are redirected to login for the word blocks stats index', function () {
    $this->get('/profile/quads')->assertRedirect('/login');
});

test('guests are redirected to login for a word blocks game detail page', function () {
    $user = User::factory()->create();
    $participant = quartilesParticipantFor($user);

    $this->get("/profile/quads/{$participant->id}")->assertRedirect('/login');
});

test('the word blocks stats index renders for an authenticated user', function () {
    $user = User::factory()->create();
    quartilesParticipantFor($user, score: 48);

    $this->actingAs($user)
        ->get('/profile/quads')
        ->assertSeeLivewire('pages::profile.quartiles.index')
        ->assertSee('48');
});

test('the word blocks stats index only shows the current user\'s own games', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    quartilesParticipantFor($user);
    $otherGame = quartilesParticipantFor($otherUser, score: 30);

    Livewire::actingAs($user)
        ->test('pages::profile.quartiles.index')
        ->assertCount('games', 1)
        ->assertDontSee(route('profile.quartiles.show', $otherGame), escape: false);
});

test('the word blocks stats index links the highest-score personal best to the game it came from', function () {
    $user = User::factory()->create();
    $best = quartilesParticipantFor($user, score: 50);

    $this->actingAs($user)
        ->get('/profile/quads')
        ->assertSee(route('profile.quartiles.show', $best), escape: false);
});

test('a word blocks game detail page shows its headline stats', function () {
    $user = User::factory()->create();
    $participant = quartilesParticipantFor($user, score: 62, wordsFound: 8, quartilesFound: 3);

    $this->actingAs($user)
        ->get("/profile/quads/{$participant->id}")
        ->assertSeeLivewire('pages::profile.quartiles.show')
        ->assertSee('62')
        ->assertSee('8')
        ->assertSee('3/5');
});

test('a word blocks game detail page lists found words', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::Quartiles, 'mode' => GameMode::Solo]);
    $participant = GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => 10,
        'stats' => ['words_found' => 2, 'quartiles_found' => 0, 'found_words' => ['cats' => 8, 'at' => 1]],
    ]);

    $this->actingAs($user)
        ->get("/profile/quads/{$participant->id}")
        ->assertSee('CATS')
        ->assertSee('AT');
});

test('a user cannot view another user\'s word blocks game detail page', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $participant = quartilesParticipantFor($otherUser);

    $this->actingAs($user)
        ->get("/profile/quads/{$participant->id}")
        ->assertNotFound();
});

test('a word blocks game detail page 404s for a non-Quartiles participant', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::Boggle]);
    $participant = GameParticipant::factory()->create(['game_session_id' => $session->id, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->get("/profile/quads/{$participant->id}")
        ->assertNotFound();
});

test('the dashboard links the word blocks tile to the word blocks stats page once linkable', function () {
    $user = User::factory()->create(['username' => 'blocks-fan']);
    quartilesParticipantFor($user);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSee(route('profile.quartiles'), escape: false);
});
