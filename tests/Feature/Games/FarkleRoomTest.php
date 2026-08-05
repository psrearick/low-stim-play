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

/**
 * See tests/Feature/Games/YahtzeeRoomTest.php's own identical helper for why
 * this drives game-lobby and pages::farkle.room separately rather than
 * relying on one bubbling into the other.
 *
 * @return array<int, array{id: int, name: string, is_host: bool, ready: bool}>
 */
function readyUpAndStartFarkle(Room $room, User $host, User $guest): array
{
    Livewire::actingAs($host)
        ->test('game-lobby', ['room' => $room, 'currentGame' => GameType::Farkle])
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

    $this->actingAs($guest)->get('/farkle')->assertSee('Host an Online Room');

    $user = User::factory()->create();

    $this->actingAs($user)->get('/farkle')->assertSee('Host an Online Room');

    $this->get('/farkle')->assertSee('Host an Online Room');
});

test('a logged-out visitor can host a room, auto-provisioning a guest account', function () {
    Livewire::test('pages::farkle.play')
        ->set('guestName', 'Alex')
        ->call('createRoom')
        ->assertRedirect();

    $room = Room::sole();
    $host = User::find($room->host_user_id);

    expect($host->is_guest)->toBeTrue()
        ->and($host->name)->toBe('Alex');
});

test('joining with a valid code redirects to the farkle room', function () {
    $room = Room::factory()->create();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::farkle.play')
        ->set('joinID', strtolower($room->code))
        ->call('joinRoom')
        ->assertRedirect(route('farkle.room', ['code' => $room->code]));
});

test('a guest can view the farkle room page without an account, and is given a throwaway one', function () {
    $room = Room::factory()->create();

    $this->get("/farkle/room/{$room->code}")->assertOk();

    $guest = User::where('is_guest', true)->sole();

    expect($guest->is_guest)->toBeTrue()
        ->and(auth()->id())->toBe($guest->id);
});

test('the host can configure target score and final round before starting', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    Livewire::actingAs($host)
        ->test('pages::farkle.room', ['code' => $room->code])
        ->assertSet('targetScore', 10000)
        ->assertSet('withFinalRound', true)
        ->set('targetScore', 5000)
        ->set('withFinalRound', false)
        ->call('onRoomStarting', [
            ['id' => $host->id, 'name' => $host->name, 'is_host' => true, 'ready' => true],
        ]);

    $session = GameSession::sole();

    expect($session->settings['target_score'])->toBe(5000)
        ->and($session->settings['with_final_round'])->toBeFalse()
        ->and($session->state['targetScore'])->toBe(5000)
        ->and($session->state['withFinalRound'])->toBeFalse();
});

test('starting the room seat-assigns every ready player and persists an initial state blob', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStartFarkle($room, $host, $guest);

    Livewire::actingAs($host)
        ->test('pages::farkle.room', ['code' => $room->code])
        ->call('onRoomStarting', $players)
        ->assertRedirect(route('farkle.play', ['room' => $room->code]));

    $session = GameSession::sole();

    expect($session->type)->toBe(GameType::Farkle)
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

    $players = readyUpAndStartFarkle($room, $host, $guest);

    $component = Livewire::actingAs($host)->test('pages::farkle.room', ['code' => $room->code]);
    $component->call('onRoomStarting', $players);
    $component->call('onRoomStarting', $players);

    expect(GameSession::count())->toBe(1)
        ->and(RoomSeat::count())->toBe(2);
});

test('a genuine replay does not duplicate seat rows', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStartFarkle($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::farkle.room', ['code' => $room->code])->call('onRoomStarting', $players);

    expect(RoomSeat::where('room_id', $room->id)->count())->toBe(2);

    $players = readyUpAndStartFarkle($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::farkle.room', ['code' => $room->code])->call('onRoomStarting', $players);

    expect(GameSession::where('type', GameType::Farkle)->count())->toBe(2)
        ->and(RoomSeat::where('room_id', $room->id)->count())->toBe(2);
});

test('only the current seat can act, and a non-current seat is forbidden', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStartFarkle($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::farkle.room', ['code' => $room->code])->call('onRoomStarting', $players);

    // Seat 0 (host, the first to ready up) goes first.
    Livewire::actingAs($guest)
        ->test('pages::farkle.play', ['room' => $room->code])
        ->call('roll')
        ->assertForbidden();

    // Not assertCount('dice', 6) -- a fresh 6-dice roll has a small but
    // real chance of legitimately busting on its own (see
    // FarkleGameTest's "the first roll fills all 6 dice" for the same
    // caveat), which would make this flake for a reason unrelated to what
    // this test actually checks: that the host, unlike the guest above,
    // isn't forbidden from acting on their own turn.
    Livewire::actingAs($host)
        ->test('pages::farkle.play', ['room' => $room->code])
        ->assertSet('mySeatIndex', 0)
        ->call('roll')
        ->assertSet('mySeatIndex', 0);
});

test('an unseated spectator sees no seat and cannot mutate the game', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $spectator = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStartFarkle($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::farkle.room', ['code' => $room->code])->call('onRoomStarting', $players);

    Livewire::actingAs($spectator)
        ->test('pages::farkle.play', ['room' => $room->code])
        ->assertSet('mySeatIndex', null)
        ->call('roll')
        ->assertForbidden();
});

test('reloading mid-game rehydrates the correct seat and in-progress state instead of resetting to setup', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStartFarkle($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::farkle.room', ['code' => $room->code])->call('onRoomStarting', $players);

    Livewire::actingAs($host)
        ->test('pages::farkle.play', ['room' => $room->code])
        ->set('dice', [1, 1, 1, 1, 1, 2])
        ->call('toggleSelect', 0)->call('toggleSelect', 1)->call('toggleSelect', 2)->call('toggleSelect', 3)
        ->call('bank');

    // A brand-new component instance, as a fresh page load/reconnect would
    // be -- rehydrated entirely from the persisted GameSession.state.
    Livewire::actingAs($guest)
        ->test('pages::farkle.play', ['room' => $room->code])
        ->assertSet('mySeatIndex', 1)
        ->assertSet('started', true)
        ->assertSet('currentPlayer', 1)
        ->assertSet('players.0.bankedScore', 2000)
        ->assertSet('players.0.hasQualified', true);
});

test('a full online game writes one distinct GameParticipant per logged-in account', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpAndStartFarkle($room, $host, $guest);
    Livewire::actingAs($host)->test('pages::farkle.room', ['code' => $room->code])->call('onRoomStarting', $players);

    Livewire::actingAs($host)
        ->test('pages::farkle.play', ['room' => $room->code])
        ->set('dice', [1, 1, 1, 1, 1, 2])
        ->call('toggleSelect', 0)->call('toggleSelect', 1)->call('toggleSelect', 2)->call('toggleSelect', 3)
        ->call('bank')
        ->call('endGameManually');

    expect(GameParticipant::count())->toBe(2)
        ->and(GameParticipant::where('user_id', $host->id)->sole()->score)->toBe(2000)
        ->and(GameParticipant::where('user_id', $guest->id)->sole()->score)->toBe(0);
});
