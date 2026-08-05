<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the setup screen renders', function () {
    $this->get('/farkle')->assertSeeLivewire('pages::farkle.play');
});

test('player count is clamped between 1 and 8', function () {
    Livewire::test('pages::farkle.play')
        ->call('setPlayerCount', 0)
        ->assertSet('playerCount', 1)
        ->call('setPlayerCount', 9)
        ->assertSet('playerCount', 8);
});

test('changing player count preserves already-typed names', function () {
    Livewire::test('pages::farkle.play')
        ->set('playerNames.0', 'Alex')
        ->call('setPlayerCount', 3)
        ->assertSet('playerNames.0', 'Alex')
        ->assertSet('playerNames', ['Alex', '', '']);
});

test('starting the game builds players with typed or default names and zeroed stats', function () {
    Livewire::test('pages::farkle.play')
        ->call('setPlayerCount', 2)
        ->set('playerNames.0', 'Alex')
        ->set('playerNames.1', '')
        ->call('startGame')
        ->assertSet('started', true)
        ->assertSet('players.0.name', 'Alex')
        ->assertSet('players.0.bankedScore', 0)
        ->assertSet('players.0.hasQualified', false)
        ->assertSet('players.1.name', 'Player 2')
        ->assertSet('currentPlayer', 0)
        ->assertSet('dice', []);
});

/**
 * A fresh 6-dice roll has a small (~2.3%) real chance of legitimately
 * busting on its own -- see the "rolling again..." test below for the
 * same class of caveat in more detail. Retrying until a non-bust roll is
 * observed keeps this test about "a successful roll fills all 6 dice,"
 * not "did this specific roll happen not to bust."
 */
test('the first roll fills all 6 dice', function () {
    $component = null;

    for ($attempt = 0; $attempt < 30; $attempt++) {
        $component = Livewire::test('pages::farkle.play')->call('startGame')->call('roll');

        if ($component->get('dice') !== []) {
            break;
        }
    }

    $component->assertCount('dice', 6)
        ->tap(function ($component) {
            foreach ($component->get('dice') as $value) {
                expect($value)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(6);
            }
        });
});

test('selecting a non-scoring die makes rollAgain and bank both no-ops', function () {
    // Not ->call('roll') then ->set('dice', ...) -- a real roll() call
    // has a genuine chance of busting for real before the override ever
    // takes effect, which (for tests pinned to a specific player index
    // below) silently shifts whose turn it actually is. Setting $dice
    // directly is just as valid: toggleSelect()/bank()/rollAgain() only
    // ever read the current $dice/$selected state, never how it got
    // there.
    Livewire::test('pages::farkle.play')
        ->call('startGame')
        ->set('dice', [2, 3, 4, 6, 6, 3])
        ->call('toggleSelect', 0) // the lone 2 -- scores nothing
        ->assertSet('canRollAgain', false)
        ->assertSet('canBank', false)
        ->call('bank')
        ->assertSet('dice', [2, 3, 4, 6, 6, 3]) // unchanged -- bank() was a no-op
        ->assertSet('players.0.bankedScore', 0);
});

test('banking below the minimum entry score before qualifying is blocked', function () {
    Livewire::test('pages::farkle.play')
        ->call('startGame')
        ->set('dice', [1, 2, 3, 4, 6, 6])
        ->call('toggleSelect', 0) // a single 1 = 100, well under the 500 minimum
        ->assertSet('canBank', false)
        ->call('bank')
        ->assertSet('players.0.bankedScore', 0) // unchanged
        ->assertSet('currentPlayer', 0); // turn never advanced
});

test('banking at least the minimum entry score qualifies the player and advances the turn', function () {
    Livewire::test('pages::farkle.play')
        ->call('setPlayerCount', 2)
        ->call('startGame')
        ->set('dice', [1, 1, 1, 1, 1, 2]) // four 1s = 2000
        ->call('toggleSelect', 0)->call('toggleSelect', 1)->call('toggleSelect', 2)->call('toggleSelect', 3)
        ->assertSet('canBank', true)
        ->call('bank')
        ->assertSet('players.0.bankedScore', 2000)
        ->assertSet('players.0.hasQualified', true)
        ->assertSet('currentPlayer', 1)
        ->assertSet('dice', [])
        ->assertSet('turnScore', 0);
});

test('once qualified, a player can bank any amount, even under the minimum', function () {
    $component = Livewire::test('pages::farkle.play')
        ->call('setPlayerCount', 1)
        ->call('startGame')
        ->set('dice', [1, 1, 1, 1, 1, 2])
        ->call('toggleSelect', 0)->call('toggleSelect', 1)->call('toggleSelect', 2)->call('toggleSelect', 3)
        ->call('bank') // qualifies with 2000, turn wraps back to player 0 (only 1 player)
        ->assertSet('players.0.hasQualified', true);

    $component->set('dice', [1, 2, 3, 4, 6, 6])
        ->call('toggleSelect', 0) // just a single 1 = 100, under 500
        ->assertSet('canBank', true)
        ->call('bank')
        ->assertSet('players.0.bankedScore', 2100);
});

/**
 * rollAgain() applies the selection *and* immediately rolls the
 * remaining dice for real -- so that follow-up roll can itself
 * legitimately bust (a real ~7.7% chance for 4 dice), which correctly
 * zeroes turnScore right back out as part of normal play. Retrying until
 * a non-bust outcome is observed (almost always the first try) tests the
 * actual claim here -- "applying the selection worked" -- without being
 * at the mercy of the very next roll's own unrelated randomness.
 */
test('rolling again applies the selection and immediately rerolls only the remaining dice', function () {
    $component = null;

    for ($attempt = 0; $attempt < 30; $attempt++) {
        $component = Livewire::test('pages::farkle.play')
            ->call('startGame')
            ->set('dice', [1, 1, 2, 3, 4, 6])
            ->call('toggleSelect', 0)->call('toggleSelect', 1) // two 1s = 200
            ->call('rollAgain');

        if ($component->get('dice') !== []) {
            break;
        }
    }

    $component->assertSet('turnScore', 200)
        ->assertSet('heldDiceCount', 2)
        ->assertCount('dice', 4);
});

/**
 * Same real-follow-up-roll caveat as the test above, just with a smaller
 * (~2.3%) bust chance on the fresh 6-dice hot-dice reroll.
 */
test('setting aside every die earns hot dice -- a fresh set of 6, turn score kept', function () {
    $component = null;

    for ($attempt = 0; $attempt < 30; $attempt++) {
        $component = Livewire::test('pages::farkle.play')
            ->call('startGame')
            ->set('dice', [1, 1, 1, 1, 1, 1]) // six 1s = 8000, all 6 dice score
            ->call('toggleSelect', 0)->call('toggleSelect', 1)->call('toggleSelect', 2)
            ->call('toggleSelect', 3)->call('toggleSelect', 4)->call('toggleSelect', 5)
            ->call('rollAgain');

        if ($component->get('dice') !== []) {
            break;
        }
    }

    $component->assertSet('turnScore', 8000)
        ->assertSet('heldDiceCount', 0) // reset, not left at 6
        ->assertCount('dice', 6); // a fresh full set, not 0
});

/**
 * There's no way to force a specific real dice roll through roll()'s own
 * DiceRoller call without mocking it -- and this suite deliberately never
 * mocks a game's randomness (see e.g. YahtzeeGameTest's own real ->call
 * ('roll') calls, only ever pinned down afterward via ->set('dice', ...)).
 * A single die busts unless it lands 1 or 5 (4-in-6 odds each try), so
 * forcing heldDiceCount down to 5 first and retrying a handful of fresh
 * components reaches an observed bust in practice almost immediately,
 * without the cross-test container leakage a mocked singleton caused here
 * (confirmed while first writing this test with $this->mock(): the bound
 * fake silently made *later*, unrelated tests' real rolls bust too, since
 * this suite's app container is reused across tests within a process).
 */
test('a roll with no scoring dice at all busts the turn, losing its points and advancing play', function () {
    $farkled = false;
    $component = null;

    for ($attempt = 0; $attempt < 30 && ! $farkled; $attempt++) {
        $component = Livewire::test('pages::farkle.play')
            ->call('setPlayerCount', 2)
            ->call('startGame')
            ->set('heldDiceCount', 5)
            ->call('roll');

        $farkled = $component->get('dice') === [];
    }

    expect($farkled)->toBeTrue();
    $component->assertSet('turnScore', 0)
        ->assertSet('players.0.farkleCount', 1)
        ->assertSet('currentPlayer', 1)
        ->assertSee('rolled a Farkle', false);
});

test('a bust loses only this turn\'s points, not points already banked in earlier turns', function () {
    $farkled = false;
    $component = null;

    for ($attempt = 0; $attempt < 30 && ! $farkled; $attempt++) {
        $component = Livewire::test('pages::farkle.play')
            ->call('setPlayerCount', 1)
            ->call('startGame')
            ->set('dice', [1, 1, 1, 1, 1, 2]) // four 1s = 2000
            ->call('toggleSelect', 0)->call('toggleSelect', 1)->call('toggleSelect', 2)->call('toggleSelect', 3)
            ->call('bank') // banks 2000, turn wraps back to player 0 (only 1 player)
            ->set('heldDiceCount', 5)
            ->call('roll');

        $farkled = $component->get('dice') === [];
    }

    expect($farkled)->toBeTrue();
    $component->assertSet('players.0.bankedScore', 2000) // untouched by the bust
        ->assertSet('players.0.farkleCount', 1);
});

test('an early manual end-game is allowed for any player in local pass-and-play', function () {
    Livewire::test('pages::farkle.play')
        ->call('setPlayerCount', 2)
        ->call('startGame')
        ->set('dice', [1, 1, 1, 1, 1, 2])
        ->call('toggleSelect', 0)->call('toggleSelect', 1)->call('toggleSelect', 2)->call('toggleSelect', 3)
        ->call('bank')
        ->call('endGameManually')
        ->assertSet('gameOver', true);

    $session = GameSession::sole();
    expect($session->type)->toBe(GameType::Farkle)
        ->and($session->mode)->toBe(GameMode::Local);

    expect(GameParticipant::count())->toBe(2);
});

test('an early manual end-game in an online room is restricted to the host', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $session = GameSession::factory()->create([
        'type' => GameType::Farkle,
        'mode' => GameMode::Online,
        'state' => farkleOnlineState([$host, $guest]),
    ]);
    $room->update(['game_session_id' => $session->id]);
    RoomSeat::create(['room_id' => $room->id, 'seat_index' => 0, 'user_id' => $host->id]);
    RoomSeat::create(['room_id' => $room->id, 'seat_index' => 1, 'user_id' => $guest->id]);

    Livewire::actingAs($guest)
        ->test('pages::farkle.play', ['room' => $room->code])
        ->call('endGameManually')
        ->assertForbidden();

    Livewire::actingAs($host)
        ->test('pages::farkle.play', ['room' => $room->code])
        ->call('endGameManually')
        ->assertSet('gameOver', true);
});

test('starting a new game returns to the setup screen', function () {
    Livewire::test('pages::farkle.play')
        ->call('setPlayerCount', 1)
        ->call('startGame')
        ->call('endGameManually')
        ->set('gameOver', true)
        ->call('newGame')
        ->assertSet('started', false)
        ->assertSet('gameOver', false)
        ->assertSet('players', []);
});

test('choosing a player as "you" attributes that participant to your account, others stay guests', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::farkle.play')
        ->call('setPlayerCount', 2)
        ->set('playerNames.0', 'Alex')
        ->set('playerNames.1', 'Sam')
        ->set('meIndex', 0)
        ->call('startGame')
        ->call('endGameManually');

    $mine = GameParticipant::where('user_id', $user->id)->sole();
    $other = GameParticipant::whereNull('user_id')->sole();

    expect($mine->guest_name)->toBeNull()
        ->and($other->guest_name)->toBe('Sam');
});

/**
 * Builds a minimal valid online-room GameSession.state for Farkle, mirroring
 * exactly what pages::farkle.room's own onRoomStarting() would write.
 *
 * @param  list<User>  $users
 * @return array<string, mixed>
 */
function farkleOnlineState(array $users): array
{
    return [
        'seats' => array_map(
            fn (User $user, int $index) => [
                'seatIndex' => $index,
                'name' => $user->name,
                'bankedScore' => 0,
                'hasQualified' => false,
                'farkleCount' => 0,
                'hotDiceCount' => 0,
                'bestTurnScore' => 0,
            ],
            $users,
            array_keys($users),
        ),
        'currentSeat' => 0,
        'dice' => [],
        'selected' => [],
        'turnScore' => 0,
        'heldDiceCount' => 0,
        'targetScore' => 10000,
        'withFinalRound' => true,
        'finalRoundActive' => false,
        'finalRoundRemaining' => null,
        'gameOver' => false,
        'message' => null,
    ];
}
