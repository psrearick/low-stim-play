<?php

use App\Enums\GameResult;
use App\Models\GameParticipant;
use App\Models\GameSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Isolates the win-condition/final-round bookkeeping from real dice --
 * every bank() call here forces $dice directly first, so the outcome
 * being tested (target crossed, final round countdown, game end) never
 * depends on chance the way an actual roll would. $selected is
 * deliberately never set -- bank() auto-includes every scoring die in
 * $dice regardless of manual selection (see docs/concerns.md), so these
 * helpers double as coverage of that.
 */
function bankSixOfAKind(mixed $component): mixed
{
    // Not ->call('roll') then ->set('dice', ...) -- see FarkleGameTest's
    // own comment on the same pattern: a real roll() has a genuine chance
    // of busting for real before the override takes effect, which here
    // would silently shift whose turn it actually is, before any of this
    // helper's own forced dice ever applies.
    return $component
        ->set('dice', [1, 1, 1, 1, 1, 1]) // six of a kind is a flat 3000, regardless of face
        ->call('bank');
}

/**
 * Not a single 1 (only 100 points) -- a player who hasn't already
 * qualified this game needs at least the 500-point minimum entry for
 * bank() to actually do anything (see FarkleGameTest's own "banking
 * below the minimum entry score" test), so every helper here clears
 * that bar. Four of a kind, not three 1s (docs/TODO.md's former #22
 * dropped three 1s from 1000 to 300, which alone would fall short of
 * that same 500-point minimum).
 */
function bankFourSixes(mixed $component): mixed
{
    return $component
        ->set('dice', [6, 6, 6, 6, 2, 3]) // four 6s = 1000 flat, 2/3 unused
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
        ->call('bank');
}

test('with the final round on, crossing the target gives every other player exactly one more turn', function () {
    $component = Livewire::test('pages::farkle.play')
        ->call('setPlayerCount', 3)
        ->set('targetScore', 1000)
        ->set('withFinalRound', true)
        ->call('startGame');

    // Player 0 crosses 1000 in one turn (six of a kind = 3000).
    bankSixOfAKind($component)
        ->assertSet('players.0.bankedScore', 3000)
        ->assertSet('finalRoundActive', true)
        ->assertSet('finalRoundRemaining', 2)
        ->assertSet('gameOver', false)
        ->assertSet('currentPlayer', 1);

    // Player 1's final turn -- doesn't cross the target themselves, game
    // continues to player 2.
    bankFourSixes($component)
        ->assertSet('players.1.bankedScore', 1000)
        ->assertSet('finalRoundRemaining', 1)
        ->assertSet('gameOver', false)
        ->assertSet('currentPlayer', 2);

    // Player 2's final turn -- the last one; game ends here regardless of
    // whether they also cross the target.
    bankFourSixes($component)
        ->assertSet('players.2.bankedScore', 1000)
        ->assertSet('gameOver', true);

    // Player 0's 3000 still wins outright.
    $session = GameSession::sole();
    $winner = GameParticipant::where('score', 3000)->sole();
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

    bankSixOfAKind($component)
        ->assertSet('gameOver', true)
        ->assertSet('players.0.bankedScore', 3000)
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

    bankSixOfAKind($component) // player 0 crosses, triggers the final round
        ->assertSet('finalRoundActive', true);

    // Player 1's one final turn also banks exactly 3000 -- a tie.
    bankSixOfAKind($component)
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

    bankSixOfAKind($component) // 3000 qualifies outright
        ->assertSet('players.0.bankedScore', 3000)
        ->assertSet('currentPlayer', 1);

    $component->call('endGameManually')
        ->assertSet('gameOver', true);

    $winner = GameParticipant::where('score', 3000)->sole();
    $loser = GameParticipant::where('score', 0)->sole();

    expect($winner->result)->toBe(GameResult::Win)
        ->and($loser->result)->toBe(GameResult::Loss);
});

test('bank() auto-includes every scoring die even when none were manually clicked', function () {
    // Regression coverage for docs/concerns.md's "if there are scoring
    // dice they did not click, those should be added up and banked too"
    // -- $selected is never touched here at all.
    $component = Livewire::test('pages::farkle.play')
        ->call('setPlayerCount', 1)
        ->call('startGame')
        ->set('dice', [4, 4, 4, 1, 5]) // three 4s (400) + a 1 (100) + a 5 (50) = 550
        ->assertSet('selected', [])
        ->call('bank');

    expect($component->get('players.0.bankedScore'))->toBe(550);
});

test('the Bank button label always matches what actually gets banked', function () {
    // Regression coverage for docs/concerns.md's "the Bank amount... is
    // wrong" -- bankableAmount (used for the label) and what bank()
    // actually credits must never disagree.
    $component = Livewire::test('pages::farkle.play')
        ->call('setPlayerCount', 1)
        ->call('startGame')
        ->set('dice', [4, 4, 4, 1, 5]);

    expect($component->get('bankableAmount'))->toBe(550);

    $component->call('bank');

    expect($component->get('players.0.bankedScore'))->toBe(550);
});
