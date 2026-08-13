<?php

use App\Enums\GameMode;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Enums\YahtzeeCategory;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the setup screen renders', function () {
    $this->get('/yahtzee')->assertSeeLivewire('pages::yahtzee.play');
});

test('player count is clamped between 1 and 8', function () {
    Livewire::test('pages::yahtzee.play')
        ->call('setPlayerCount', 0)
        ->assertSet('playerCount', 1)
        ->call('setPlayerCount', 9)
        ->assertSet('playerCount', 8);
});

test('changing player count preserves already-typed names', function () {
    Livewire::test('pages::yahtzee.play')
        ->set('playerNames.0', 'Alex')
        ->call('setPlayerCount', 3)
        ->assertSet('playerNames.0', 'Alex')
        ->assertSet('playerNames', ['Alex', '', '']);
});

test('starting the game builds players with typed or default names', function () {
    Livewire::test('pages::yahtzee.play')
        ->call('setPlayerCount', 2)
        ->set('playerNames.0', 'Alex')
        ->set('playerNames.1', '')
        ->call('startGame')
        ->assertSet('started', true)
        ->assertSet('players.0.name', 'Alex')
        ->assertSet('players.1.name', 'Player 2')
        ->assertSet('currentPlayer', 0)
        ->assertSet('rollsLeft', 3)
        ->assertSet('dice', []);
});

test('the first roll fills all 5 dice and decrements rolls left', function () {
    Livewire::test('pages::yahtzee.play')
        ->call('startGame')
        ->call('roll')
        ->assertCount('dice', 5)
        ->assertSet('rollsLeft', 2);

    // Every value is a real die face.
    Livewire::test('pages::yahtzee.play')
        ->call('startGame')
        ->call('roll')
        ->tap(function ($component) {
            foreach ($component->get('dice') as $value) {
                expect($value)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(6);
            }
        });
});

test('kept dice are not changed by a reroll', function () {
    $component = Livewire::test('pages::yahtzee.play')
        ->call('startGame')
        ->call('roll')
        ->set('dice', [1, 2, 3, 4, 5])
        ->call('toggleKeep', 0)
        ->call('toggleKeep', 1)
        ->call('roll');

    expect($component->get('dice')[0])->toBe(1)
        ->and($component->get('dice')[1])->toBe(2)
        ->and($component->get('rollsLeft'))->toBe(1);
});

test('dice cannot be kept before the first roll', function () {
    Livewire::test('pages::yahtzee.play')
        ->call('startGame')
        ->call('toggleKeep', 0)
        ->assertSet('kept', []);
});

test('rolling more than 3 times in a turn is a no-op', function () {
    Livewire::test('pages::yahtzee.play')
        ->call('startGame')
        ->call('roll')
        ->call('roll')
        ->call('roll')
        ->assertSet('rollsLeft', 0)
        ->call('roll')
        ->assertSet('rollsLeft', 0);
});

test('scoring a category requires having rolled', function () {
    Livewire::test('pages::yahtzee.play')
        ->call('startGame')
        ->call('scoreCategory', 'chance')
        ->assertSet('players.0.scores.chance', null);
});

test('scoring an upper category records the correct value and advances the turn', function () {
    Livewire::test('pages::yahtzee.play')
        ->call('setPlayerCount', 2)
        ->call('startGame')
        ->call('roll')
        ->set('dice', [4, 4, 4, 2, 1])
        ->call('scoreCategory', 'fours')
        ->assertSet('players.0.scores.fours', 12)
        ->assertSet('currentPlayer', 1)
        ->assertSet('dice', [])
        ->assertSet('rollsLeft', 3);
});

test('a filled category cannot be scored again', function () {
    Livewire::test('pages::yahtzee.play')
        ->call('setPlayerCount', 1)
        ->call('startGame')
        ->call('roll')
        ->set('dice', [1, 1, 1, 1, 1])
        ->call('scoreCategory', 'chance')
        ->assertSet('players.0.scores.chance', 5)
        ->assertSet('currentPlayer', 0) // only 1 player, turn wraps back
        ->call('roll')
        ->set('dice', [6, 6, 6, 6, 6])
        ->call('scoreCategory', 'chance')
        ->assertSet('players.0.scores.chance', 5); // unchanged
});

test('an extra Yahtzee after the box is filled awards a bonus chip', function () {
    Livewire::test('pages::yahtzee.play')
        ->call('setPlayerCount', 1)
        ->call('startGame')
        ->call('roll')
        ->set('dice', [5, 5, 5, 5, 5])
        ->call('scoreCategory', 'yahtzee')
        ->assertSet('players.0.scores.yahtzee', 50)
        ->assertSet('players.0.yahtzeeBonusCount', 0)
        ->call('roll')
        ->set('dice', [3, 3, 3, 3, 3])
        ->call('scoreCategory', 'chance')
        ->assertSet('players.0.yahtzeeBonusCount', 1);
});

test('the Joker rule lets an extra Yahtzee fill Full House once the matching upper box is filled', function () {
    Livewire::test('pages::yahtzee.play')
        ->call('setPlayerCount', 1)
        ->call('startGame')
        ->call('roll')
        ->set('dice', [5, 5, 5, 5, 5])
        ->call('scoreCategory', 'yahtzee') // fills yahtzee with 50
        ->call('roll')
        ->set('dice', [3, 3, 3, 3, 3])
        ->call('scoreCategory', 'threes') // fills the matching upper box (9)
        ->call('roll')
        ->set('dice', [3, 3, 3, 3, 3]) // another Yahtzee, joker-eligible now
        ->call('scoreCategory', 'full_house')
        ->assertSet('players.0.scores.full_house', 25);
});

test('the game ends once every player has filled every category, recording a session and ranked participants', function () {
    $component = Livewire::test('pages::yahtzee.play')
        ->call('setPlayerCount', 2)
        ->set('playerNames.0', 'Alex')
        ->set('playerNames.1', 'Sam')
        ->call('startGame');

    $categories = array_map(fn ($c) => $c->value, YahtzeeCategory::all());

    foreach ($categories as $category) {
        // Player 1 (Alex): always scores via a strong roll where possible.
        $component->call('roll')->set('dice', [6, 6, 6, 6, 6])->call('scoreCategory', $category);
        // Player 2 (Sam): a weak, low-scoring roll.
        $component->call('roll')->set('dice', [1, 1, 2, 3, 4])->call('scoreCategory', $category);
    }

    $component->assertSet('gameOver', true);

    $session = GameSession::sole();

    expect($session->type)->toBe(GameType::Yahtzee)
        ->and($session->mode)->toBe(GameMode::Local)
        ->and($session->settings['player_count'])->toBe(2);

    expect(GameParticipant::count())->toBe(2);

    $alex = GameParticipant::where('guest_name', 'Alex')->sole();
    $sam = GameParticipant::where('guest_name', 'Sam')->sole();

    expect($alex->score)->toBeGreaterThan($sam->score)
        ->and($alex->result)->toBe(GameResult::Win)
        ->and($sam->result)->toBe(GameResult::Loss);
});

test('starting a new game returns to the setup screen', function () {
    Livewire::test('pages::yahtzee.play')
        ->call('setPlayerCount', 1)
        ->call('startGame')
        ->call('roll')
        ->set('dice', [1, 1, 1, 1, 1])
        ->call('scoreCategory', 'chance')
        ->set('gameOver', true)
        ->call('newGame')
        ->assertSet('started', false)
        ->assertSet('gameOver', false)
        ->assertSet('players', []);
});

test('undo is off by default and does nothing until enabled (docs/concerns.md)', function () {
    Livewire::test('pages::yahtzee.play')
        ->assertSet('undoEnabled', false)
        ->call('startGame')
        ->call('roll')
        ->assertCount('dice', 5)
        ->call('undo')
        ->assertCount('dice', 5)
        ->assertSet('rollsLeft', 2);
});

test('undo reverts the most recent roll when enabled', function () {
    Livewire::test('pages::yahtzee.play')
        ->set('undoEnabled', true)
        ->call('startGame')
        ->call('roll')
        ->assertCount('dice', 5)
        ->assertSet('rollsLeft', 2)
        ->call('undo')
        ->assertSet('dice', [])
        ->assertSet('rollsLeft', 3)
        ->assertSet('history', []);
});

test('undo can never revert a scored category, even enabled -- it is roll-only now (docs/concerns.md)', function () {
    // The old undo() used to be able to step back across a turn boundary
    // and effectively undo a score, since scoreCategory() pushed its own
    // history snapshot too. That's no longer possible: scoreCategory()
    // never calls pushHistory(), and startTurn() clears $history at every
    // turn boundary, so there is nothing left to pop once a turn has
    // advanced -- undo() becomes a no-op instead of reaching backwards
    // into the previous player's turn.
    Livewire::test('pages::yahtzee.play')
        ->set('undoEnabled', true)
        ->call('setPlayerCount', 2)
        ->call('startGame')
        ->call('roll')
        ->set('dice', [4, 4, 4, 2, 1])
        ->call('scoreCategory', 'fours')
        ->assertSet('players.0.scores.fours', 12)
        ->assertSet('currentPlayer', 1)
        ->assertSet('history', [])
        ->call('undo')
        ->assertSet('players.0.scores.fours', 12)
        ->assertSet('currentPlayer', 1);
});

test('undo does nothing when there is no history, even enabled', function () {
    Livewire::test('pages::yahtzee.play')
        ->set('undoEnabled', true)
        ->call('startGame')
        ->call('undo')
        ->assertSet('history', [])
        ->assertSet('dice', [])
        ->assertSet('rollsLeft', 3);
});

test('undo does nothing once the game is over, even enabled', function () {
    Livewire::test('pages::yahtzee.play')
        ->set('undoEnabled', true)
        ->call('setPlayerCount', 1)
        ->call('startGame')
        ->call('roll')
        ->set('dice', [1, 1, 1, 1, 1])
        ->call('scoreCategory', 'chance')
        ->set('gameOver', true)
        ->call('undo')
        ->assertSet('players.0.scores.chance', 5);
});

test('a roll cannot be undone into the previous turn, only within the current one', function () {
    Livewire::test('pages::yahtzee.play')
        ->set('undoEnabled', true)
        ->call('setPlayerCount', 2)
        ->call('startGame')
        ->call('roll')
        ->set('dice', [4, 4, 4, 2, 1])
        ->call('scoreCategory', 'fours')
        ->assertSet('currentPlayer', 1)
        ->assertSet('history', [])
        ->call('roll')
        ->assertCount('history', 1)
        ->call('undo')
        ->assertSet('currentPlayer', 1)
        ->assertSet('dice', []);
});

test('tapping a scorecard cell requests confirmation instead of scoring immediately (docs/concerns.md)', function () {
    Livewire::test('pages::yahtzee.play')
        ->call('setPlayerCount', 1)
        ->call('startGame')
        ->call('roll')
        ->set('dice', [4, 4, 4, 2, 1])
        ->call('requestScore', 'fours')
        ->assertSet('pendingCategory', 'fours')
        ->assertSet('players.0.scores.fours', null)
        ->assertSee('Score this roll as Fours for 12 points?')
        ->call('scoreCategory', 'fours')
        ->assertSet('pendingCategory', null)
        ->assertSet('players.0.scores.fours', 12);
});

test('cancelScore clears the pending category without scoring', function () {
    Livewire::test('pages::yahtzee.play')
        ->call('setPlayerCount', 1)
        ->call('startGame')
        ->call('roll')
        ->set('dice', [4, 4, 4, 2, 1])
        ->call('requestScore', 'fours')
        ->assertSet('pendingCategory', 'fours')
        ->call('cancelScore')
        ->assertSet('pendingCategory', null)
        ->assertSet('players.0.scores.fours', null);
});

test('requestScore is a no-op for a category that is not actually pickable', function () {
    Livewire::test('pages::yahtzee.play')
        ->call('setPlayerCount', 1)
        ->call('startGame')
        // No roll yet -- nothing is pickable.
        ->call('requestScore', 'fours')
        ->assertSet('pendingCategory', null);
});

test('changing player count clears an out-of-range "which player is you" selection', function () {
    Livewire::test('pages::yahtzee.play')
        ->call('setPlayerCount', 3)
        ->set('meIndex', 2)
        ->call('setPlayerCount', 2)
        ->assertSet('meIndex', null);
});

test('changing player count keeps an in-range "which player is you" selection', function () {
    Livewire::test('pages::yahtzee.play')
        ->call('setPlayerCount', 3)
        ->set('meIndex', 1)
        ->call('setPlayerCount', 2)
        ->assertSet('meIndex', 1);
});

test('choosing a player as "you" attributes that participant to your account, others stay guests', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::yahtzee.play')
        ->call('setPlayerCount', 2)
        ->set('playerNames.0', 'Alex')
        ->set('playerNames.1', 'Sam')
        ->set('meIndex', 0)
        ->call('startGame');

    $categories = array_map(fn ($c) => $c->value, YahtzeeCategory::all());

    foreach ($categories as $category) {
        $component->call('roll')->set('dice', [6, 6, 6, 6, 6])->call('scoreCategory', $category);
        $component->call('roll')->set('dice', [1, 1, 2, 3, 4])->call('scoreCategory', $category);
    }

    $mine = GameParticipant::where('user_id', $user->id)->sole();
    $other = GameParticipant::whereNull('user_id')->sole();

    expect($mine->guest_name)->toBeNull()
        ->and($other->guest_name)->toBe('Sam');
});

test('leaving "not me" selected keeps every participant a guest', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::yahtzee.play')
        ->call('setPlayerCount', 2)
        ->call('startGame');

    $categories = array_map(fn ($c) => $c->value, YahtzeeCategory::all());

    foreach ($categories as $category) {
        $component->call('roll')->set('dice', [6, 6, 6, 6, 6])->call('scoreCategory', $category);
        $component->call('roll')->set('dice', [1, 1, 2, 3, 4])->call('scoreCategory', $category);
    }

    expect(GameParticipant::whereNull('user_id')->count())->toBe(2);
});
