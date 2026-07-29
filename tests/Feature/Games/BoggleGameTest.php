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
 * 2x2 board spelling CAT / CATS, matching tests/Unit/Boggle/SolverTest.php's
 * fixture -- every cell is mutually adjacent to every other cell.
 */
function boggleCatsBoard(): array
{
    return [
        'size' => 4,
        'seed' => 1,
        'cells' => [
            ['index' => 0, 'row' => 0, 'col' => 0, 'display' => 'C', 'match' => 'c'],
            ['index' => 1, 'row' => 0, 'col' => 1, 'display' => 'A', 'match' => 'a'],
            ['index' => 2, 'row' => 1, 'col' => 0, 'display' => 'T', 'match' => 't'],
            ['index' => 3, 'row' => 1, 'col' => 1, 'display' => 'S', 'match' => 's'],
        ],
        'neighbors' => [
            [1, 2, 3],
            [0, 2, 3],
            [0, 1, 3],
            [0, 1, 2],
        ],
    ];
}

test('the lobby renders the setup form', function () {
    $this->get('/boggle')->assertSeeLivewire('boggle-setup');
});

test('the setup form redirects to the play page with the chosen options', function () {
    Livewire::test('boggle-setup')
        ->set('size', 5)
        ->set('minutes', 2)
        ->call('startGame')
        ->assertRedirect(route('boggle.play', ['size' => 5, 'minutes' => 2]));
});

test('the play page generates a board of the requested size', function () {
    Livewire::test('pages::boggle.play', ['size' => 5, 'minutes' => 3])
        ->assertSet('size', 5)
        ->assertCount('board.cells', 25);
});

test('an invalid size falls back to 4', function () {
    Livewire::test('pages::boggle.play', ['size' => 7, 'minutes' => 3])
        ->assertSet('size', 4);
});

test('selecting cells builds a path, skipping non-adjacent and already-selected cells', function () {
    Livewire::test('pages::boggle.play', ['size' => 4, 'minutes' => 3])
        ->set('board', boggleCatsBoard())
        ->call('selectCell', 0)
        ->call('selectCell', 0) // already selected, ignored
        ->assertSet('path', [0])
        ->call('selectCell', 1)
        ->assertSet('path', [0, 1]);
});

test('clearing the path resets it', function () {
    Livewire::test('pages::boggle.play', ['size' => 4, 'minutes' => 3])
        ->set('board', boggleCatsBoard())
        ->call('selectCell', 0)
        ->call('selectCell', 1)
        ->call('clearPath')
        ->assertSet('path', []);
});

test('submitting a valid word scores points and records it as found', function () {
    Livewire::test('pages::boggle.play', ['size' => 4, 'minutes' => 3])
        ->set('board', boggleCatsBoard())
        ->set('path', [0, 1, 2])
        ->call('submitWord')
        ->assertSet('path', [])
        ->assertSet('foundWords', ['cat' => 1])
        ->assertSet('score', 1)
        ->assertSet('message', '+1 point!');
});

test('submitting an already-found word is rejected', function () {
    Livewire::test('pages::boggle.play', ['size' => 4, 'minutes' => 3])
        ->set('board', boggleCatsBoard())
        ->set('path', [0, 1, 2])
        ->call('submitWord')
        ->set('path', [0, 1, 2])
        ->call('submitWord')
        ->assertSet('score', 1)
        ->assertSet('message', 'Already found.');
});

test('submitting a word too short is rejected', function () {
    Livewire::test('pages::boggle.play', ['size' => 4, 'minutes' => 3])
        ->set('board', boggleCatsBoard())
        ->set('path', [0, 1])
        ->call('submitWord')
        ->assertSet('score', 0)
        ->assertSet('message', 'Too short.');
});

test('submitting a path that spells a non-word is rejected', function () {
    Livewire::test('pages::boggle.play', ['size' => 4, 'minutes' => 3])
        ->set('board', boggleCatsBoard())
        ->set('path', [2, 0, 1]) // "tca"
        ->call('submitWord')
        ->assertSet('score', 0)
        ->assertSet('message', 'Not a word.');
});

test('ending the game reveals all valid words and records a solo game session', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3])
        ->set('board', boggleCatsBoard())
        ->set('path', [0, 1, 2])
        ->call('submitWord')
        ->call('endGame')
        ->assertSet('ended', true)
        // The board's 4 cells are fully connected (every cell is adjacent
        // to every other), so every anagram-like arrangement of C/A/T/S
        // that happens to be a real word is reachable, not just cat/cats.
        ->assertSet('allValidWords', ['act', 'acts', 'cast', 'cat', 'cats', 'sac', 'sat', 'scat']);

    $session = GameSession::sole();

    expect($session->type)->toBe(GameType::Boggle)
        ->and($session->mode)->toBe(GameMode::Solo)
        ->and($session->ended_at)->not->toBeNull();

    $participant = GameParticipant::sole();

    expect($participant->game_session_id)->toBe($session->id)
        ->and($participant->user_id)->toBe($user->id)
        ->and($participant->guest_name)->toBeNull()
        ->and($participant->score)->toBe(1);
});

test('a guest playing solo is still recorded, without a user', function () {
    Livewire::test('pages::boggle.play', ['size' => 4, 'minutes' => 3])
        ->call('endGame');

    $participant = GameParticipant::sole();

    expect($participant->user_id)->toBeNull()
        ->and($participant->guest_name)->toBe('Guest');
});

test('an untimed game never polls or auto-ends', function () {
    Livewire::test('pages::boggle.play', ['size' => 4, 'minutes' => 0])
        ->assertSet('endsAt', null)
        ->assertDontSee('wire:poll', escape: false);
});

test('starting a new game resets state but keeps the size and time limit', function () {
    Livewire::test('pages::boggle.play', ['size' => 4, 'minutes' => 3])
        ->set('board', boggleCatsBoard())
        ->set('path', [0, 1, 2])
        ->call('submitWord')
        ->call('endGame')
        ->call('resetGame')
        ->assertSet('ended', false)
        ->assertSet('score', 0)
        ->assertSet('foundWords', [])
        ->assertSet('size', 4)
        ->assertSet('minutes', 3);
});
