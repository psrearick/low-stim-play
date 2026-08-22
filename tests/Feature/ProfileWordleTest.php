<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function wordleParticipantFor(User $user, int $score = 400, bool $won = true, ?int $guessCount = 3, bool $hardMode = false): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::Wordle, 'mode' => GameMode::Solo]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $score,
        'stats' => [
            'won' => $won,
            'guess_count' => $guessCount,
            'hard_mode' => $hardMode,
            'guesses' => [],
        ],
    ]);
}

test('guests are redirected to login for the five letters stats index', function () {
    $this->get('/profile/five-letters')->assertRedirect('/login');
});

test('guests are redirected to login for a five letters game detail page', function () {
    $user = User::factory()->create();
    $participant = wordleParticipantFor($user);

    $this->get("/profile/five-letters/{$participant->id}")->assertRedirect('/login');
});

test('the five letters stats index renders for an authenticated user', function () {
    $user = User::factory()->create();
    wordleParticipantFor($user, score: 500);

    $this->actingAs($user)
        ->get('/profile/five-letters')
        ->assertSeeLivewire('pages::profile.wordle.index')
        ->assertSee('500');
});

test('the five letters stats index only shows the current user\'s own games', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    wordleParticipantFor($user);
    $otherGame = wordleParticipantFor($otherUser, score: 300);

    Livewire::actingAs($user)
        ->test('pages::profile.wordle.index')
        ->assertCount('games', 1)
        ->assertDontSee(route('profile.wordle.show', $otherGame), escape: false);
});

test('the five letters stats index links the highest-score personal best to the game it came from', function () {
    $user = User::factory()->create();
    $best = wordleParticipantFor($user, score: 600);

    $this->actingAs($user)
        ->get('/profile/five-letters')
        ->assertSee(route('profile.wordle.show', $best), escape: false);
});

test('a five letters game detail page shows its headline stats', function () {
    $user = User::factory()->create();
    $participant = wordleParticipantFor($user, score: 500, won: true, guessCount: 2, hardMode: true);

    $this->actingAs($user)
        ->get("/profile/five-letters/{$participant->id}")
        ->assertSeeLivewire('pages::profile.wordle.show')
        ->assertSee('500')
        ->assertSee('Won')
        ->assertSee('2');
});

test('a five letters game detail page renders its guess history', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::Wordle, 'mode' => GameMode::Solo]);
    $participant = GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => 500,
        'stats' => [
            'won' => true,
            'guess_count' => 1,
            'hard_mode' => false,
            'guesses' => [
                ['word' => 'crane', 'result' => ['correct', 'correct', 'correct', 'correct', 'correct']],
            ],
        ],
    ]);

    $this->actingAs($user)
        ->get("/profile/five-letters/{$participant->id}")
        ->assertSee('C')
        ->assertSee('R')
        ->assertSee('A')
        ->assertSee('N')
        ->assertSee('E');
});

test('a user cannot view another user\'s five letters game detail page', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $participant = wordleParticipantFor($otherUser);

    $this->actingAs($user)
        ->get("/profile/five-letters/{$participant->id}")
        ->assertNotFound();
});

test('a five letters game detail page 404s for a non-Wordle participant', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::Boggle]);
    $participant = GameParticipant::factory()->create(['game_session_id' => $session->id, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->get("/profile/five-letters/{$participant->id}")
        ->assertNotFound();
});

test('the dashboard links the five letters tile to the five letters stats page once linkable', function () {
    $user = User::factory()->create(['username' => 'five-letters-fan']);
    wordleParticipantFor($user);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSee(route('profile.wordle'), escape: false);
});
