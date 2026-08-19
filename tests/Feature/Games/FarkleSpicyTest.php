<?php

use App\Enums\GameResult;
use App\Models\GameParticipant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Mirrors FarkleWinConditionTest's own approach: $dice (and, here,
 * $dieSpicy) are forced directly rather than driven through a real roll(),
 * since this suite never mocks the app's randomness (see FarkleGameTest's
 * own comment on that). docs/specs/farkle.md §6 is the spec these are
 * checked against.
 */
test('setSpicyEnabled toggles the setup screen\'s default target score', function () {
    Livewire::test('pages::farkle.play')
        ->assertSet('targetScore', 10000)
        ->call('setSpicyEnabled', true)
        ->assertSet('targetScore', 25000)
        ->call('setSpicyEnabled', false)
        ->assertSet('targetScore', 10000);
});

test('a spicy die doubles the combination it is part of when banked', function () {
    $component = Livewire::test('pages::farkle.play')
        ->call('setPlayerCount', 1)
        ->set('spicyEnabled', true)
        ->call('startGame')
        ->set('dice', [4, 4, 4, 2, 1])
        ->set('dieSpicy', [false, false, true, false, false]) // the third 4 is spicy
        ->call('bank');

    // Three 4s doubled (800) + the single 1 (100, not spicy) = 900.
    expect($component->get('players.0.bankedScore'))->toBe(900);
});

test('spicy scoring is inert when the variant is off, even if dieSpicy is somehow set', function () {
    $component = Livewire::test('pages::farkle.play')
        ->call('setPlayerCount', 1)
        ->call('startGame')
        ->set('dice', [4, 4, 4, 2, 1])
        ->set('dieSpicy', [false, false, true, false, false])
        ->call('bank');

    expect($component->get('players.0.bankedScore'))->toBe(500);
});

/**
 * All six dice landing on their own spicy label has a real-world
 * probability of (1/6)^6 -- far too low to reach via real rolls even with
 * the generous retry counts this suite's other bust/farkle tests use (see
 * FarkleGameTest's own comment on why it never mocks DiceRoller instead).
 * GameEngineTest's own isInstantWin() coverage already confirms
 * performRoll() would reach winInstantly() once a real roll produces
 * all-spicy flags; this test isolates winInstantly()'s own consequences
 * (forced game end, correct winner/loser results) via reflection into
 * that one private method, the same way the rest of this file forces
 * $dice/$dieSpicy directly instead of trusting a real roll to land there.
 */
test('rolling all six dice spicy is an instant win, ending the game regardless of banked score', function () {
    $component = Livewire::test('pages::farkle.play')
        ->call('setPlayerCount', 2)
        ->set('spicyEnabled', true)
        ->set('targetScore', 25000)
        ->call('startGame');

    // Player 0 has *not* reached the target at all -- the instant win
    // must still end the game in their favor regardless.
    expect($component->get('players.0.bankedScore'))->toBe(0);

    $component->set('dice', [1, 2, 3, 4, 5, 6])
        ->set('dieSpicy', [true, true, true, true, true, true])
        ->set('selected', array_fill(0, 6, false));

    $winInstantly = new ReflectionMethod($component->instance(), 'winInstantly');
    $winInstantly->setAccessible(true);
    $winInstantly->invoke($component->instance());

    // ->get()/->instance() read the live PHP object directly, unlike
    // assertSee() (which re-renders through Livewire's own snapshot cycle
    // and wouldn't reflect a reflection-based mutation like this one).
    expect($component->get('gameOver'))->toBeTrue()
        ->and($component->get('players.0.bankedScore'))->toBe(0)
        ->and($component->get('message'))->toContain('instant win');

    $winner = GameParticipant::where('score', 0)->where('result', GameResult::Win)->sole();
    $loser = GameParticipant::where('result', GameResult::Loss)->sole();

    expect($winner)->not->toBeNull()
        ->and($loser)->not->toBeNull();
});

test('High Stakes offers the next player a take-over only after a Bank, not a bust', function () {
    $component = Livewire::test('pages::farkle.play')
        ->call('setPlayerCount', 2)
        ->set('highStakesEnabled', true)
        ->call('startGame')
        ->set('dice', [1, 1, 1, 1, 2, 3]) // four 1s (1000) + two dead dice
        ->set('activeDieSlots', [1, 2, 3, 4, 5, 6])
        ->call('bank');

    expect($component->get('currentPlayer'))->toBe(1)
        ->and($component->get('players.0.bankedScore'))->toBe(1000)
        ->and($component->get('lastBankInheritance'))->toBe(['turnScore' => 1000, 'remainingSlots' => [5, 6]])
        ->and($component->get('canTakeOver'))->toBeTrue();
});

test('High Stakes take-over starts the new turn at the previous turn\'s full banked amount, rerolling only the leftover dice', function () {
    $component = Livewire::test('pages::farkle.play')
        ->call('setPlayerCount', 2)
        ->set('highStakesEnabled', true)
        ->call('startGame')
        ->set('dice', [1, 1, 1, 1, 2, 3]) // banks 1000, leaves 2 dice (slots 5 and 6) non-frozen
        ->set('activeDieSlots', [1, 2, 3, 4, 5, 6])
        ->call('bank')
        ->call('rollTakeOver');

    // The 2 rerolled dice are a real, uncontrolled roll (see FarkleGameTest's
    // own comment on why this suite never mocks DiceRoller), so either they
    // scored (turn score is the inherited 1000 plus whatever they added) or
    // they busted -- which, per docs/specs/farkle.md §6.5, loses the *entire*
    // current turn score, inherited head start included, not just what was
    // rolled just now.
    if ($component->get('dice') === []) {
        expect($component->get('turnScore'))->toBe(0);
    } else {
        expect($component->get('turnScore'))->toBeGreaterThanOrEqual(1000)
            ->and(count($component->get('dice')))->toBe(2);
    }

    expect($component->get('lastBankInheritance'))->toBeNull(); // consumed either way
});

test('High Stakes never offers a take-over after a bust', function () {
    $farkled = false;
    $component = null;

    for ($attempt = 0; $attempt < 30 && ! $farkled; $attempt++) {
        $component = Livewire::test('pages::farkle.play')
            ->call('setPlayerCount', 2)
            ->set('highStakesEnabled', true)
            ->call('startGame')
            ->set('heldDiceCount', 5)
            ->set('activeDieSlots', [1])
            ->call('roll');

        $farkled = $component->get('dice') === [];
    }

    expect($farkled)->toBeTrue();
    $component->assertSet('lastBankInheritance', null)
        ->assertSet('canTakeOver', false);
});

test('declining a High Stakes take-over (rolling fresh) forfeits it for the rest of the game', function () {
    $component = Livewire::test('pages::farkle.play')
        ->call('setPlayerCount', 2)
        ->set('highStakesEnabled', true)
        ->call('startGame')
        ->set('dice', [1, 1, 1, 1, 2, 3])
        ->set('activeDieSlots', [1, 2, 3, 4, 5, 6])
        ->call('bank');

    expect($component->get('lastBankInheritance'))->not->toBeNull();

    $component->call('roll');

    expect($component->get('lastBankInheritance'))->toBeNull();
});

test('High Stakes still offers a take-over when the previous bank used all six dice, falling back to a fresh six', function () {
    $component = Livewire::test('pages::farkle.play')
        ->call('setPlayerCount', 2)
        ->set('highStakesEnabled', true)
        ->call('startGame')
        ->set('dice', [1, 1, 1, 1, 1, 1]) // six of a kind = 3000 flat, every die scores
        ->set('activeDieSlots', [1, 2, 3, 4, 5, 6])
        ->call('bank');

    expect($component->get('lastBankInheritance'))->toBe(['turnScore' => 3000, 'remainingSlots' => [1, 2, 3, 4, 5, 6]]);
});
