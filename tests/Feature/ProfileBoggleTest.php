<?php

use App\Enums\GameMode;
use App\Enums\GameResult;
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
function boggleParticipantFor(User $user, array $foundWords = ['cat' => 1], int $score = 1, ?int $totalValidWords = null, GameMode $mode = GameMode::Solo, ?GameResult $result = null): GameParticipant
{
    $session = GameSession::factory()->create(['type' => GameType::Boggle, 'mode' => $mode]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $score,
        'result' => $result,
        'stats' => array_filter([
            'words_found' => count($foundWords),
            'found_words' => $foundWords,
            'total_valid_words' => $totalValidWords,
        ], fn ($value) => $value !== null),
    ]);
}

test('guests are redirected to login for the boggle stats index', function () {
    $this->get('/profile/word-grid')->assertRedirect('/login');
});

test('guests are redirected to login for a boggle game detail page', function () {
    $user = User::factory()->create();
    $participant = boggleParticipantFor($user);

    $this->get("/profile/word-grid/{$participant->id}")->assertRedirect('/login');
});

test('the boggle stats index renders for an authenticated user', function () {
    $user = User::factory()->create();
    boggleParticipantFor($user, ['cat' => 1, 'plate' => 2], 3, totalValidWords: 10);

    $this->actingAs($user)
        ->get('/profile/word-grid')
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

test('the boggle stats index shows a win/loss result for online games and a dash for solo games', function () {
    $user = User::factory()->create();
    boggleParticipantFor($user, ['cat' => 1], 5, mode: GameMode::Online, result: GameResult::Win);
    boggleParticipantFor($user, ['dog' => 1], 0, mode: GameMode::Online, result: GameResult::Loss);
    boggleParticipantFor($user, ['owl' => 1], 3, mode: GameMode::Solo);

    $this->actingAs($user)
        ->get('/profile/word-grid')
        ->assertSee('Win')
        ->assertSee('Loss')
        ->assertSee('—');
});

test('the boggle stats index links each personal best to the game it came from', function () {
    $user = User::factory()->create();
    $best = boggleParticipantFor($user, ['elephant' => 11], 11);

    $this->actingAs($user)
        ->get('/profile/word-grid')
        ->assertSee(route('profile.boggle.show', $best), escape: false);
});

test('the boggle stats index shows the average percent of the board found across games with a recorded board size', function () {
    $user = User::factory()->create();
    boggleParticipantFor($user, ['cat' => 1, 'dog' => 1], 2, totalValidWords: 4); // 50%
    boggleParticipantFor($user, ['cat' => 1], 1, totalValidWords: 10); // 10%

    $this->actingAs($user)
        ->get('/profile/word-grid')
        ->assertSee('Average % of board found')
        ->assertSeeInOrder(['average-percent-found', '30']);
});

test('the average-percent-found tile is omitted when no game has a recorded board size', function () {
    $user = User::factory()->create();
    boggleParticipantFor($user, ['cat' => 1], 1);

    $this->actingAs($user)
        ->get('/profile/word-grid')
        ->assertDontSee('Average % of board found');
});

test('a boggle game detail page shows its stats and found words', function () {
    $user = User::factory()->create();
    $participant = boggleParticipantFor($user, ['cat' => 1, 'plate' => 2], 3, totalValidWords: 10);

    $this->actingAs($user)
        ->get("/profile/word-grid/{$participant->id}")
        ->assertSeeLivewire('pages::profile.boggle.show')
        ->assertSee('CAT')
        ->assertSee('PLATE')
        ->assertSee('20%'); // 2 of 10 words found
});

test('a boggle game detail page shows points earned from words over 4 letters', function () {
    $user = User::factory()->create();
    // cat (3) doesn't count; plate (5, 2pts) and dragon (6, 3pts) do.
    $participant = boggleParticipantFor($user, ['cat' => 1, 'plate' => 2, 'dragon' => 3], 6);

    $this->actingAs($user)
        ->get("/profile/word-grid/{$participant->id}")
        ->assertSee('Points from long words')
        ->assertSee('5');
});

test('the boggle stats index surfaces the best points-from-long-words game as a personal best', function () {
    $user = User::factory()->create();
    $best = boggleParticipantFor($user, ['dragon' => 3], 3);
    boggleParticipantFor($user, ['cat' => 1], 1);

    $this->actingAs($user)
        ->get('/profile/word-grid')
        ->assertSee('Most points from long words')
        ->assertSee(route('profile.boggle.show', $best), escape: false);
});

test('a boggle game detail page shows the result for an online game', function () {
    $user = User::factory()->create();
    $participant = boggleParticipantFor($user, ['cat' => 1], 5, mode: GameMode::Online, result: GameResult::Win);

    $this->actingAs($user)
        ->get("/profile/word-grid/{$participant->id}")
        ->assertSee('Win');
});

test('a boggle game detail page omits the result tile entirely for a solo game', function () {
    $user = User::factory()->create();
    $participant = boggleParticipantFor($user, ['cat' => 1], 5, mode: GameMode::Solo);

    $this->actingAs($user)
        ->get("/profile/word-grid/{$participant->id}")
        ->assertDontSee('Result');
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
        ->get("/profile/word-grid/{$participant->id}")
        ->assertNotFound();
});

test('a boggle game detail page 404s for a non-Boggle participant', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::Yahtzee]);
    $participant = GameParticipant::factory()->create(['game_session_id' => $session->id, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->get("/profile/word-grid/{$participant->id}")
        ->assertNotFound();
});
