<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function spellingBeeParticipantFor(User $user, int $score = 20, int $wordsFound = 0, int $pangramsFound = 0): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::SpellingBee, 'mode' => GameMode::Solo]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $score,
        'stats' => [
            'words_found' => $wordsFound,
            'pangrams_found' => $pangramsFound,
            'found_words' => [],
        ],
    ]);
}

test('guests are redirected to login for the word hive stats index', function () {
    $this->get('/profile/word-hive')->assertRedirect('/login');
});

test('guests are redirected to login for a word hive game detail page', function () {
    $user = User::factory()->create();
    $participant = spellingBeeParticipantFor($user);

    $this->get("/profile/word-hive/{$participant->id}")->assertRedirect('/login');
});

test('the word hive stats index renders for an authenticated user', function () {
    $user = User::factory()->create();
    spellingBeeParticipantFor($user, score: 48);

    $this->actingAs($user)
        ->get('/profile/word-hive')
        ->assertSeeLivewire('pages::profile.spelling-bee.index')
        ->assertSee('48');
});

test('the word hive stats index only shows the current user\'s own games', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    spellingBeeParticipantFor($user);
    $otherGame = spellingBeeParticipantFor($otherUser, score: 30);

    Livewire::actingAs($user)
        ->test('pages::profile.spelling-bee.index')
        ->assertCount('games', 1)
        ->assertDontSee(route('profile.spelling-bee.show', $otherGame), escape: false);
});

test('the word hive stats index links the highest-score personal best to the game it came from', function () {
    $user = User::factory()->create();
    $best = spellingBeeParticipantFor($user, score: 50);

    $this->actingAs($user)
        ->get('/profile/word-hive')
        ->assertSee(route('profile.spelling-bee.show', $best), escape: false);
});

test('a word hive game detail page shows its headline stats', function () {
    $user = User::factory()->create();
    $participant = spellingBeeParticipantFor($user, score: 62, wordsFound: 8, pangramsFound: 1);

    $this->actingAs($user)
        ->get("/profile/word-hive/{$participant->id}")
        ->assertSeeLivewire('pages::profile.spelling-bee.show')
        ->assertSee('62')
        ->assertSee('8');
});

test('a word hive game detail page lists found words', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::SpellingBee, 'mode' => GameMode::Solo]);
    $participant = GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => 10,
        'stats' => ['words_found' => 2, 'pangrams_found' => 0, 'found_words' => ['couple' => 3, 'cued' => 1]],
    ]);

    $this->actingAs($user)
        ->get("/profile/word-hive/{$participant->id}")
        ->assertSee('COUPLE')
        ->assertSee('CUED');
});

test('a user cannot view another user\'s word hive game detail page', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $participant = spellingBeeParticipantFor($otherUser);

    $this->actingAs($user)
        ->get("/profile/word-hive/{$participant->id}")
        ->assertNotFound();
});

test('a word hive game detail page 404s for a non-SpellingBee participant', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::Boggle]);
    $participant = GameParticipant::factory()->create(['game_session_id' => $session->id, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->get("/profile/word-hive/{$participant->id}")
        ->assertNotFound();
});

test('the dashboard links the word hive tile to the word hive stats page once linkable', function () {
    $user = User::factory()->create(['username' => 'hive-fan']);
    spellingBeeParticipantFor($user);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSee(route('profile.spelling-bee'), escape: false);
});
