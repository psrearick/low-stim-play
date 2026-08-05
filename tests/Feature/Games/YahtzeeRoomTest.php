<?php

use App\Enums\GameMode;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Enums\YahtzeeCategory;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Drives game-lobby through readying up both players and clicking "Start
 * Game" (this is what actually resets Room.game_session_id/status, exactly
 * like BoggleRoomTest's own "replaying a room" test does), then separately
 * drives pages::yahtzee.room's own onRoomStarting(), passing the same
 * players payload game-lobby's real dispatch would have carried --
 * Livewire::test() exercises each component in isolation, so (unlike a
 * real page, where the nested <livewire:game-lobby> component's dispatched
 * event bubbles straight up to this ancestor listener in the same request)
 * nothing here bubbles between these two separate test harnesses on its
 * own.
 *
 * @return array<int, array{id: int, name: string, is_host: bool, ready: bool}>
 */
function readyUpAndStart(Room $room, User $host, User $guest): array
{
    Livewire::actingAs($host)
        ->test('game-lobby', ['room' => $room, 'currentGame' => GameType::Yahtzee])
        ->call('onJoining', ['id' => $host->id, 'name' => $host->name, 'is_host' => true])
        ->call('onJoining', ['id' => $guest->id, 'name' => $guest->name, 'is_host' => false])
        ->call('onReadyToggled', ['user_id' => $host->id, 'ready' => true])
        ->call('onReadyToggled', ['user_id' => $guest->id, 'ready' => true])
        ->call('startGame');

    return [
        ['id' => $host->id, 'name' => $host->name, 'is_host' => true, 'ready' => true],
        ['id' => $guest->id, 'name' => $guest->name, 'is_host' => false, 'ready' => true],
    ];
}

test('the setup screen always shows the host button, regardless of auth state', function () {
    $guest = User::createGuest('Alex');

    $this->actingAs($guest)->get('/yahtzee')->assertSee('Host an Online Room');

    $user = User::factory()->create();

    $this->actingAs($user)->get('/yahtzee')->assertSee('Host an Online Room');

    $this->get('/yahtzee')->assertSee('Host an Online Room');
});

test('a logged-out visitor can host a room, auto-provisioning a guest account', function () {
    Livewire::test('pages::yahtzee.play')
        ->set('guestName', 'Alex')
        ->call('createRoom')
        ->assertRedirect();

    $room = Room::sole();
    $host = User::find($room->host_user_id);

    expect($host->is_guest)->toBeTrue()
        ->and($host->name)->toBe('Alex');
});

test('joining with a valid code redirects to the yahtzee room', function () {
    $room = Room::factory()->create();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::yahtzee.play')
        ->set('joinID', strtolower($room->code))
        ->call('joinRoom')
        ->assertRedirect(route('yahtzee.room', ['code' => $room->code]));
});

test('a guest can view the yahtzee room page without an account, and is given a throwaway one', function () {
    $room = Room::factory()->create();

    $this->get("/yahtzee/room/{$room->code}")->assertOk();

    $guest = User::where('is_guest', true)->sole();

    expect($guest->is_guest)->toBeTrue()
        ->and(auth()->id())->toBe($guest->id);
});

test('starting the room seat-assigns every ready player and persists an initial state blob', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);

    Livewire::actingAs($host)
        ->test('pages::yahtzee.room', ['code' => $room->code])
        ->call('onRoomStarting', $players)
        ->assertRedirect(route('yahtzee.play', ['room' => $room->code]));

    $session = GameSession::sole();

    expect($session->type)->toBe(GameType::Yahtzee)
        ->and($session->mode)->toBe(GameMode::Online)
        ->and($session->settings['player_count'])->toBe(2)
        ->and($session->state['seats'])->toHaveCount(2)
        ->and($session->state['currentSeat'])->toBe(0)
        ->and($session->state['gameOver'])->toBeFalse()
        ->and($room->fresh()->game_session_id)->toBe($session->id);

    expect(RoomSeat::count())->toBe(2)
        ->and(RoomSeat::where('room_id', $room->id)->where('user_id', $host->id)->sole()->seat_index)->toBe(0)
        ->and(RoomSeat::where('room_id', $room->id)->where('user_id', $guest->id)->sole()->seat_index)->toBe(1);
});

test('starting the room twice does not create a second game session or duplicate seats', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);

    $component = Livewire::actingAs($host)->test('pages::yahtzee.room', ['code' => $room->code]);
    $component->call('onRoomStarting', $players);
    $component->call('onRoomStarting', $players);

    expect(GameSession::count())->toBe(1)
        ->and(RoomSeat::count())->toBe(2);
});

test('a genuine replay (via game-lobby\'s own reset) does not duplicate seat rows', function () {
    // Regression test: the previous test above only proves calling
    // onRoomStarting() twice *in a row* (game_session_id already non-null
    // the second time) is a no-op -- it never actually reset
    // game_session_id in between, so it never exercised the real replay
    // path. A genuine replay goes through game-lobby's startGame(), which
    // *does* reset game_session_id to null on every "Start Game" click,
    // not just the first -- and RoomSeat::create() used to run again
    // unconditionally inside that reopened branch, leaving the *previous*
    // game's seat rows in place and simply adding a second set on top.
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::yahtzee.room', ['code' => $room->code])->call('onRoomStarting', $players);

    expect(RoomSeat::where('room_id', $room->id)->count())->toBe(2);

    $players = readyUpAndStart($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::yahtzee.room', ['code' => $room->code])->call('onRoomStarting', $players);

    expect(GameSession::where('type', GameType::Yahtzee)->count())->toBe(2)
        ->and(RoomSeat::where('room_id', $room->id)->count())->toBe(2);
});

test('only the current seat can roll, and a non-current seat is forbidden', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::yahtzee.room', ['code' => $room->code])->call('onRoomStarting', $players);

    // Seat 0 (host, the first to ready up) goes first.
    Livewire::actingAs($guest)
        ->test('pages::yahtzee.play', ['room' => $room->code])
        ->call('roll')
        ->assertForbidden();

    Livewire::actingAs($host)
        ->test('pages::yahtzee.play', ['room' => $room->code])
        ->call('roll')
        ->assertSet('rollsLeft', 2);
});

test('an unseated spectator sees no seat and cannot mutate the game', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $spectator = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::yahtzee.room', ['code' => $room->code])->call('onRoomStarting', $players);

    Livewire::actingAs($spectator)
        ->test('pages::yahtzee.play', ['room' => $room->code])
        ->assertSet('mySeatIndex', null)
        ->call('roll')
        ->assertForbidden();
});

test('reloading mid-game rehydrates the correct seat and in-progress state instead of resetting to setup', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::yahtzee.room', ['code' => $room->code])->call('onRoomStarting', $players);

    Livewire::actingAs($host)
        ->test('pages::yahtzee.play', ['room' => $room->code])
        ->call('roll')
        ->set('dice', [5, 5, 5, 2, 2])
        ->call('scoreCategory', 'fives');

    // A brand-new component instance, as a fresh page load/reconnect would
    // be -- rehydrated entirely from the persisted GameSession.state rather
    // than any in-memory carryover.
    Livewire::actingAs($guest)
        ->test('pages::yahtzee.play', ['room' => $room->code])
        ->assertSet('mySeatIndex', 1)
        ->assertSet('started', true)
        ->assertSet('currentPlayer', 1)
        ->assertSet('players.0.scores.fives', 15);
});

test('visiting the room page after the game already started redirects straight into it, not the stale lobby', function () {
    // Regression test: a player disconnected at the exact moment the host
    // started the game would never receive RoomGameStarting (the only
    // thing onRoomStarted() reacts to) and used to be stranded on this
    // page forever. mount() now checks whether the game has already
    // started directly, independent of any broadcast.
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::yahtzee.room', ['code' => $room->code])->call('onRoomStarting', $players);

    Livewire::actingAs($guest)
        ->test('pages::yahtzee.room', ['code' => $room->code])
        ->assertRedirect(route('yahtzee.play', ['room' => $room->code]));
});

test('a spectator who visits the room page after start lands in the game as a spectator, not a seat', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $spectator = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::yahtzee.room', ['code' => $room->code])->call('onRoomStarting', $players);

    Livewire::actingAs($spectator)
        ->test('pages::yahtzee.room', ['code' => $room->code])
        ->assertRedirect(route('yahtzee.play', ['room' => $room->code]));

    Livewire::actingAs($spectator)
        ->test('pages::yahtzee.play', ['room' => $room->code])
        ->assertSet('mySeatIndex', null)
        ->assertSet('isSpectator', true);
});

test('a seated player can switch this device to spectator view and back without losing their seat', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::yahtzee.room', ['code' => $room->code])->call('onRoomStarting', $players);

    $tv = Livewire::actingAs($host)
        ->test('pages::yahtzee.play', ['room' => $room->code])
        ->assertSet('mySeatIndex', 0)
        ->assertSet('isSpectator', false)
        ->set('spectate', true)
        ->assertSet('isSpectator', true);

    // Still their seat's turn -- die-keep is suppressed by isSpectator,
    // but roll() itself is authorized purely by seat, unaffected by the
    // spectate flag, so it still works from this same "casting" device.
    $tv->call('roll')->assertSet('rollsLeft', 2);

    $tv->set('spectate', false)->assertSet('isSpectator', false);
});

test('watch-only mode never shows a live possible score for an open category, even for the active seat', function () {
    // Regression test: isPickable() (which categoryScore() also relied on)
    // checks isMyTurn, which is deliberately independent of $spectate --
    // so switching this device to "Watch only" mid-turn used to keep
    // showing the live computed possible score for every open category in
    // the active player's own column instead of leaving it blank like any
    // other unfilled, unwatched category.
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::yahtzee.room', ['code' => $room->code])->call('onRoomStarting', $players);

    $tv = Livewire::actingAs($host)
        ->test('pages::yahtzee.play', ['room' => $room->code])
        ->call('roll')
        ->set('dice', [6, 6, 6, 6, 6]);

    expect($tv->instance()->categoryScore(0, 'sixes'))->toBe(30);

    $tv->set('spectate', true);

    expect($tv->instance()->categoryScore(0, 'sixes'))->toBeNull();
    $tv->assertDontSee('30');

    $tv->set('spectate', false);

    expect($tv->instance()->categoryScore(0, 'sixes'))->toBe(30);
});

test('a non-current seat sees no pickable cell at all, even in the current player\'s own column', function () {
    // Regression test: isPickable() used to only check that the *column*
    // (`$playerIndex`) matched currentPlayer, never who was actually
    // looking at it -- so a non-current seat got a clickable
    // wire:click="scoreCategory(...)" wired up on the current player's
    // cells anyway, which then 403'd the moment they tapped it instead of
    // simply not being clickable in the first place.
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::yahtzee.room', ['code' => $room->code])->call('onRoomStarting', $players);

    Livewire::actingAs($host)->test('pages::yahtzee.play', ['room' => $room->code])
        ->call('roll')
        ->set('dice', [1, 1, 1, 1, 1]);

    // Seat 0 (host) is currentPlayer; seat 1 (guest) is not -- guest's own
    // component should see column 0 as unpickable, purely because it
    // isn't guest's turn, regardless of whose column it is.
    expect(Livewire::actingAs($guest)->test('pages::yahtzee.play', ['room' => $room->code])->instance()->isPickable(0, 'ones'))
        ->toBeFalse();
});

test('a seated player\'s main scorecard marks every other seat not-mine, for CSS to hide on mobile', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::yahtzee.room', ['code' => $room->code])->call('onRoomStarting', $players);

    Livewire::actingAs($host)
        ->test('pages::yahtzee.play', ['room' => $room->code])
        ->assertSeeHtml('single-seat-mobile')
        ->assertSeeHtml('not-mine');
});

test('a spectator\'s scorecard shows every seat, with no not-mine marking at all', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $spectator = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::yahtzee.room', ['code' => $room->code])->call('onRoomStarting', $players);

    Livewire::actingAs($spectator)
        ->test('pages::yahtzee.play', ['room' => $room->code])
        ->assertDontSeeHtml('single-seat-mobile')
        ->assertDontSeeHtml('not-mine');
});

test('a full online game writes one distinct GameParticipant per logged-in account', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::yahtzee.room', ['code' => $room->code])->call('onRoomStarting', $players);

    $categories = array_map(fn (YahtzeeCategory $category) => $category->value, YahtzeeCategory::all());
    $actors = [0 => $host, 1 => $guest];
    $dice = [0 => [6, 6, 6, 6, 6], 1 => [1, 1, 2, 3, 4]];

    foreach ($categories as $category) {
        foreach ([0, 1] as $seat) {
            Livewire::actingAs($actors[$seat])
                ->test('pages::yahtzee.play', ['room' => $room->code])
                ->call('roll')
                ->set('dice', $dice[$seat])
                ->call('scoreCategory', $category);
        }
    }

    $session = GameSession::sole();

    expect($session->state['gameOver'])->toBeTrue()
        ->and($session->ended_at)->not->toBeNull();

    expect(GameParticipant::count())->toBe(2);

    $hostParticipant = GameParticipant::where('user_id', $host->id)->sole();
    $guestParticipant = GameParticipant::where('user_id', $guest->id)->sole();

    expect($hostParticipant->guest_name)->toBeNull()
        ->and($guestParticipant->guest_name)->toBeNull()
        ->and($hostParticipant->result)->toBe(GameResult::Win)
        ->and($guestParticipant->result)->toBe(GameResult::Loss)
        ->and($hostParticipant->stats['scores'])->toBeArray();
});
