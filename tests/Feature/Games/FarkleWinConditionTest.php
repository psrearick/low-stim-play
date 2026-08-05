<?php

use App\Enums\GameResult;
use App\Models\GameParticipant;
use App\Models\GameSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Isolates the win-condition/final-round bookkeeping from real dice --
 * every bank() call here forces $dice/$selected directly first, so the
 * outcome being tested (target crossed, final round countdown, game end)
 * never depends on chance the way an actual roll would.
 */
function bankSixOnes(mixed $component): mixed
{
    // Not ->call('roll') then ->set('dice', ...) -- see FarkleGameTest's
    // own comment on the same pattern: a real roll() has a genuine chance
    // of busting for real before the override takes effect, which here
    // would silently shift whose turn it actually is, before any of this
    // helper's own forced dice/selection ever applies.
    return $component
        ->set('dice', [1, 1, 1, 1, 1, 1]) // six 1s = 8000
        ->set('selected', [true, true, true, true, true, true])
        ->call('bank');
}

/**
 * Not a single 1 (only 100 points) -- a player who hasn't already
 * qualified this game needs at least the 500-point minimum entry for
 * bank() to actually do anything (see FarkleGameTest's own "banking
 * below the minimum entry score" test), so every helper here clears
 * that bar.
 */
function bankThreeOnes(mixed $component): mixed
{
    return $component
        ->set('dice', [1, 1, 1, 2, 3, 4]) // three 1s = 1000
        ->set('selected', [true, true, true, false, false, false])
        ->call('bank');
}

/**
 * Deliberately under the 500-point minimum -- used only where the test
 * wants to exercise bank() being a no-op for an unqualified player, not
 * an actual successful bank.
 */
function bankSingleOne(mixed $component): mixed
{
    return $component
        ->set('dice', [1, 2, 3, 4, 6, 6]) // a single 1 = 100
        ->set('selected', [true, false, false, false, false, false])
        ->call('bank');
}

test('with the final round on, crossing the target gives every other player exactly one more turn', function () {
    $component = Livewire::test('pages::farkle.play')
        ->call('setPlayerCount', 3)
        ->set('targetScore', 1000)
        ->set('withFinalRound', true)
        ->call('startGame');

    // Player 0 crosses 1000 in one turn (six 1s = 8000).
    bankSixOnes($component)
        ->assertSet('players.0.bankedScore', 8000)
        ->assertSet('finalRoundActive', true)
        ->assertSet('finalRoundRemaining', 2)
        ->assertSet('gameOver', false)
        ->assertSet('currentPlayer', 1);

    // Player 1's final turn -- doesn't cross the target themselves, game
    // continues to player 2.
    bankThreeOnes($component)
        ->assertSet('players.1.bankedScore', 1000)
        ->assertSet('finalRoundRemaining', 1)
        ->assertSet('gameOver', false)
        ->assertSet('currentPlayer', 2);

    // Player 2's final turn -- the last one; game ends here regardless of
    // whether they also cross the target.
    bankThreeOnes($component)
        ->assertSet('players.2.bankedScore', 1000)
        ->assertSet('gameOver', true);

    // Player 0's 8000 still wins outright.
    $session = GameSession::sole();
    $winner = GameParticipant::where('score', 8000)->sole();
    $loser = GameParticipant::where('score', 1000)->first();

    expect($session->settings['target_score'])->toBe(1000)
        ->and($winner->result)->toBe(GameResult::Win)
        ->and($loser->result)->toBe(GameResult::Loss);
});

test('without the final round, the game ends the instant anyone crosses the target', function () {
    $component = Livewire::test('pages::farkle.play')
        ->call('setPlayerCount', 3)
        ->set('targetScore', 1000)
        ->set('withFinalRound', false)
        ->call('startGame');

    bankSixOnes($component)
        ->assertSet('gameOver', true)
        ->assertSet('players.0.bankedScore', 8000)
        // Players 1 and 2 never got a turn at all.
        ->assertSet('players.1.bankedScore', 0)
        ->assertSet('players.2.bankedScore', 0);

    expect(GameParticipant::count())->toBe(3);
});

test('a tie for the highest banked score at game end is recorded as a draw for both players', function () {
    $component = Livewire::test('pages::farkle.play')
        ->call('setPlayerCount', 2)
        ->set('targetScore', 1000)
        ->set('withFinalRound', true)
        ->call('startGame');

    bankSixOnes($component) // player 0 crosses, triggers the final round
        ->assertSet('finalRoundActive', true);

    // Player 1's one final turn also banks exactly 8000 -- a tie.
    bankSixOnes($component)
        ->assertSet('gameOver', true);

    expect(GameParticipant::where('result', GameResult::Draw)->count())->toBe(2);
});

test('a manual early end-game evaluates the current banked totals, final round or not', function () {
    $component = Livewire::test('pages::farkle.play')
        ->call('setPlayerCount', 2)
        ->call('startGame');

    bankSingleOne($component)
        ->assertSet('players.0.bankedScore', 0) // 100 is under the 500 minimum, never actually banked
        ->assertSet('currentPlayer', 0); // turn never advanced -- bank() was a no-op

    bankSixOnes($component) // 8000 qualifies outright
        ->assertSet('players.0.bankedScore', 8000)
        ->assertSet('currentPlayer', 1);

    $component->call('endGameManually')
        ->assertSet('gameOver', true);

    $winner = GameParticipant::where('score', 8000)->sole();
    $loser = GameParticipant::where('score', 0)->sole();

    expect($winner->result)->toBe(GameResult::Win)
        ->and($loser->result)->toBe(GameResult::Loss);
});
