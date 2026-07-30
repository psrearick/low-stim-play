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
 * @param  array<string, int>  $foundWords
 */
function boggleParticipantFor(User $user, array $foundWords = ['cat' => 1], int $score = 1, ?int $totalValidWords = null): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::Boggle, 'mode' => GameMode::Solo]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $score,
        'stats' => array_filter([
            'words_found' => count($foundWords),
            'found_words' => $foundWords,
            'total_valid_words' => $totalValidWords,
        ], fn ($value) => $value !== null),
    ]);
}

test('guests are redirected to login for the boggle stats index', function () {
    $this->get('/profile/boggle')->assertRedirect('/login');
});

test('guests are redirected to login for a boggle game detail page', function () {
    $user = User::factory()->create();
    $participant = boggleParticipantFor($user);

    $this->get("/profile/boggle/{$participant->id}")->assertRedirect('/login');
});

test('the boggle stats index renders for an authenticated user', function () {
    $user = User::factory()->create();
    boggleParticipantFor($user, ['cat' => 1, 'plate' => 2], 3, totalValidWords: 10);

    $this->actingAs($user)
        ->get('/profile/boggle')
        ->assertSeeLivewire('pages::profile.boggle.index')
        ->assertSee('PLATE');
});

test('the boggle stats index only shows the current user\'s own games', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    boggleParticipantFor($user, ['cat' => 1], 1);
    boggleParticipantFor($otherUser, ['zebra' => 2], 2);

    Livewire::actingAs($user)
        ->test('pages::profile.boggle.index')
        ->assertCount('games', 1)
        ->assertDontSee('ZEBRA');
});

test('the boggle stats index links each personal best to the game it came from', function () {
    $user = User::factory()->create();
    $best = boggleParticipantFor($user, ['elephant' => 11], 11);

    $this->actingAs($user)
        ->get('/profile/boggle')
        ->assertSee(route('profile.boggle.show', $best), escape: false);
});

test('a boggle game detail page shows its stats and found words', function () {
    $user = User::factory()->create();
    $participant = boggleParticipantFor($user, ['cat' => 1, 'plate' => 2], 3, totalValidWords: 10);

    $this->actingAs($user)
        ->get("/profile/boggle/{$participant->id}")
        ->assertSeeLivewire('pages::profile.boggle.show')
        ->assertSee('CAT')
        ->assertSee('PLATE')
        ->assertSee('20%'); // 2 of 10 words found
});

test('a boggle game with no recorded board size hides the percent-found stat instead of showing a bogus value', function () {
    $user = User::factory()->create();
    $participant = boggleParticipantFor($user, ['cat' => 1], 1);

    Livewire::actingAs($user)
        ->test('pages::profile.boggle.show', ['gameParticipant' => $participant])
        ->assertSet('stats.percentFound', null);
});

test('a user cannot view another user\'s boggle game detail page', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $participant = boggleParticipantFor($otherUser);

    $this->actingAs($user)
        ->get("/profile/boggle/{$participant->id}")
        ->assertNotFound();
});

test('a boggle game detail page 404s for a non-Boggle participant', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::Yahtzee]);
    $participant = GameParticipant::factory()->create(['game_session_id' => $session->id, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->get("/profile/boggle/{$participant->id}")
        ->assertNotFound();
});
