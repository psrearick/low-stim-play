<?php

use App\Enums\GameMode;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Enums\YahtzeeCategory;
use App\Events\RoomGameStateUpdated;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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

test('the setup screen shows no room UI at all for a user hosting no rooms', function () {
    // "Join a Room" is deliberately excluded from this assertion -- that's
    // the global navbar rooms-menu's own always-present action (see
    // tests/Feature/RoomsMenuTest.php), unrelated to whether *this* game's
    // own setup screen has anything room-related to show.
    $user = User::factory()->create();

    $this->actingAs($user)->get('/yacht')
        ->assertDontSee('Play in a room you host')
        ->assertDontSee('Host an Online Room');

    $this->get('/yacht')->assertDontSee('Play in a room you host');
});

test('the setup screen shows a dropdown of rooms the user hosts, linking into each one\'s lobby', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $this->actingAs($host)->get('/yacht')
        ->assertSee('Play in a room you host')
        ->assertSee($room->code)
        ->assertSeeHtml(route('yahtzee.room', ['code' => $room->code]));
});

test('a guest can view the yahtzee room page without an account, and is given a throwaway one', function () {
    $room = Room::factory()->create();

    $this->get("/yacht/room/{$room->code}")->assertOk();

    $guest = User::where('is_guest', true)->sole();

    expect($guest->is_guest)->toBeTrue()
        ->and(auth()->id())->toBe($guest->id);
});

test('a guest_name cookie is picked up as the display name for a direct-link visitor (docs/concerns.md)', function () {
    $room = Room::factory()->create();

    $this->withCookie('guest_name', 'Riley')
        ->get("/yacht/room/{$room->code}")
        ->assertOk();

    $guest = User::where('is_guest', true)->sole();

    expect($guest->name)->toBe('Riley');
});

/**
 * docs/concerns.md: config and the waiting room used to be the same
 * screen, and the instant this page loaded the room was already marked
 * pending -- everyone could join before the host had touched a setting.
 * openLobby() is the new explicit line between the two.
 */
test('landing on the room page does not mark the room pending until the host opens the lobby', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $component = Livewire::actingAs($host)->test('pages::yahtzee.room', ['code' => $room->code]);

    expect($room->fresh()->pendingGameType())->toBeNull();

    $component->call('openLobby');

    expect($room->fresh()->pendingGameType())->toBe(GameType::Yahtzee);
});

test('a non-host cannot open the lobby', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $room->addMember($guest);

    Livewire::actingAs($guest)
        ->test('pages::yahtzee.room', ['code' => $room->code])
        ->call('openLobby')
        ->assertForbidden();

    expect($room->fresh()->pendingGameType())->toBeNull();
});

test('a non-host sees a waiting message instead of the config screen or the ready-up UI before the host opens the lobby', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $room->addMember($guest);

    Livewire::actingAs($guest)
        ->test('pages::yahtzee.room', ['code' => $room->code])
        ->assertSee('Waiting for the host to finish setting up')
        ->assertDontSee('Undo')
        ->assertDontSee('Ready');
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

test('the host-only undo toggle defaults off and is baked into the GameSession settings when starting (docs/concerns.md)', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);

    Livewire::actingAs($host)
        ->test('pages::yahtzee.room', ['code' => $room->code])
        ->assertSet('undoEnabled', false)
        ->set('undoEnabled', true)
        ->call('onRoomStarting', $players);

    expect(GameSession::sole()->settings['undo_enabled'])->toBeTrue();
});

test('undo stays off for an online game when the host never enables it', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::yahtzee.room', ['code' => $room->code])->call('onRoomStarting', $players);

    Livewire::actingAs($host)
        ->test('pages::yahtzee.play', ['room' => $room->code])
        ->assertSet('undoEnabled', false);
});

test('undo works online for the current seat when the host enabled it, and broadcasts the rewind', function () {
    Event::fake([RoomGameStateUpdated::class]);

    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);
    Livewire::actingAs($host)
        ->test('pages::yahtzee.room', ['code' => $room->code])
        ->set('undoEnabled', true)
        ->call('onRoomStarting', $players);

    $tv = Livewire::actingAs($host)
        ->test('pages::yahtzee.play', ['room' => $room->code])
        ->assertSet('undoEnabled', true)
        ->call('roll')
        ->assertCount('dice', 5)
        ->call('undo')
        ->assertSet('dice', [])
        ->assertSet('rollsLeft', 3);

    Event::assertDispatched(RoomGameStateUpdated::class);
});

test('a non-current seat cannot undo online, even when the host enabled it', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);
    Livewire::actingAs($host)
        ->test('pages::yahtzee.room', ['code' => $room->code])
        ->set('undoEnabled', true)
        ->call('onRoomStarting', $players);

    Livewire::actingAs($host)->test('pages::yahtzee.play', ['room' => $room->code])->call('roll');

    // Seat 1 (guest) isn't the current seat (host, seat 0) -- forbidden
    // regardless of the room-wide undo setting, same authorization shape
    // as roll()/toggleKeep()/scoreCategory().
    Livewire::actingAs($guest)
        ->test('pages::yahtzee.play', ['room' => $room->code])
        ->call('undo')
        ->assertStatus(403);
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

test('visiting the play page for a room whose active session belongs to a different game redirects to the room hub instead of crashing', function () {
    $user = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $user->id]);
    $session = GameSession::factory()->online()->create(['type' => GameType::Boggle, 'room_code' => $room->code]);
    $room->update(['game_session_id' => $session->id]);

    Livewire::actingAs($user)
        ->test('pages::yahtzee.play', ['room' => $room->code])
        ->assertRedirect(route('room.show', ['code' => $room->code]));
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

test('watch-only mode still shows the live possible score for the active seat (docs/concerns.md)', function () {
    // docs/concerns.md: "during other players' turns, show the options
    // they can choose and the possible scores for each option, as you
    // would on your own turn" -- this intentionally reverses an earlier
    // fix (see docs/DEVLOG.md's "watch-only leaked live possible scores"
    // entry) that hid this same number from a non-acting viewer. That was
    // the right call before this request existed; the request is now for
    // the opposite behavior, so a watch-only device sees the same live
    // preview as the active seat itself, just without being able to tap
    // it -- the scorecard table's own `!$readOnly` gate (readOnly is
    // `$this->isSpectator`) is what actually keeps the cell non-
    // clickable, independent of isPickable()'s own return value, which
    // deliberately never varies with $spectate (isMyTurn, which it also
    // checks, has to stay accurate for roll()/toggleKeep()'s own server-
    // side authorization regardless of what this device merely displays).
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

    expect($tv->instance()->categoryScore(0, 'sixes'))->toBe(30);
    $tv->assertSee('30');
    // Still not clickable while watch-only, even though the number shows.
    expect($tv->instance()->isSpectator)->toBeTrue();
    $tv->assertDontSeeHtml('wire:click="scoreCategory(\'sixes\')"');

    $tv->set('spectate', false);

    expect($tv->instance()->categoryScore(0, 'sixes'))->toBe(30);
});

test('a spectator (no seat at all) also sees the current player\'s live possible scores', function () {
    // Same reversal as the watch-only test above, but for a connection
    // that never had a seat in the first place (mySeatIndex === null),
    // not just a seated player who toggled $spectate.
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $onlooker = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::yahtzee.room', ['code' => $room->code])->call('onRoomStarting', $players);

    // toggleKeep() (not just set('dice', ...)) is the real action call
    // that actually persists this forced dice roll to the shared
    // GameSession.state -- set() alone only mutates this one in-memory
    // test instance, which a fresh mount() elsewhere (the spectator's own
    // instance below) would never see.
    Livewire::actingAs($host)->test('pages::yahtzee.play', ['room' => $room->code])
        ->call('roll')
        ->set('dice', [6, 6, 6, 6, 6])
        ->call('toggleKeep', 0);

    $spectatorView = Livewire::actingAs($onlooker)->test('pages::yahtzee.play', ['room' => $room->code]);

    expect($spectatorView->instance()->isSpectator)->toBeTrue()
        ->and($spectatorView->instance()->categoryScore(0, 'sixes'))->toBe(30)
        ->and($spectatorView->instance()->isPickable(0, 'sixes'))->toBeFalse();
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

test('a seated player\'s main scorecard shows every seat\'s column, not just their own (docs/concerns.md)', function () {
    // Used to narrow to just this seat's own column on mobile (a
    // `single-seat-mobile`/`not-mine` CSS marker), with a separate
    // "Scores" drawer elsewhere on the page covering everyone else -- see
    // docs/DEVLOG.md's Yahtzee section. Removed: every column always
    // renders now, relying on horizontal scroll instead of hiding
    // columns, so both players' names should always be present.
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::yahtzee.room', ['code' => $room->code])->call('onRoomStarting', $players);

    Livewire::actingAs($host)
        ->test('pages::yahtzee.play', ['room' => $room->code])
        ->assertDontSeeHtml('single-seat-mobile')
        ->assertDontSeeHtml('not-mine')
        ->assertSee($host->name)
        ->assertSee($guest->name);
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

    // docs/concerns.md: "Back to room button does not do anything on
    // game over screen." Root cause was that Room.status/game_session_id
    // never changed once a game ended, so pages::yahtzee.room's own
    // redirectIfAlreadyStarted() kept bouncing straight back into the
    // now-finished game. Confirm both halves of the fix: the room no
    // longer reads as still-active, and visiting its lobby page actually
    // renders the lobby instead of redirecting away from it.
    expect($room->fresh()->status)->toBe(RoomStatus::Finished);

    Livewire::actingAs($host)
        ->test('pages::yahtzee.room', ['code' => $room->code])
        ->assertOk()
        ->assertSee('Yacht Room');
});

test('the host can end an online game early, scoring whatever categories are already filled (docs/concerns.md)', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::yahtzee.room', ['code' => $room->code])->call('onRoomStarting', $players);

    Livewire::actingAs($host)
        ->test('pages::yahtzee.play', ['room' => $room->code])
        ->call('endGameManually')
        ->assertSet('gameOver', true);

    $session = GameSession::sole();
    expect($session->state['gameOver'])->toBeTrue()
        ->and($session->ended_at)->not->toBeNull();

    expect($room->fresh()->status)->toBe(RoomStatus::Finished);
    expect(GameParticipant::count())->toBe(2);
});

test('a non-host cannot end an online Yahtzee game early', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStart($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::yahtzee.room', ['code' => $room->code])->call('onRoomStarting', $players);

    Livewire::actingAs($guest)
        ->test('pages::yahtzee.play', ['room' => $room->code])
        ->call('endGameManually')
        ->assertForbidden();

    expect($room->fresh()->status)->not->toBe(RoomStatus::Finished);
});
