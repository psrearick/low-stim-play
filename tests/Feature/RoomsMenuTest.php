<?php

use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('createRoom creates a room, hosts the current user, and redirects to the room hub', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('rooms-menu')
        ->call('createRoom')
        ->assertRedirect();

    $room = Room::sole();

    expect($room->host_user_id)->toBe($user->id)
        ->and($room->members()->where('user_id', $user->id)->exists())->toBeTrue();
});

test('createRoom provisions a guest account for a logged-out visitor', function () {
    Livewire::test('rooms-menu')
        ->call('createRoom')
        ->assertRedirect();

    $room = Room::sole();

    expect(User::find($room->host_user_id)->is_guest)->toBeTrue();
});

test('joinByCode with a valid code adds the current user as a member and redirects to the room hub', function () {
    $room = Room::factory()->create();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('rooms-menu')
        ->set('joinCode', strtolower($room->code))
        ->call('joinByCode')
        ->assertRedirect(route('room.show', ['code' => $room->code]));

    expect($room->members()->where('user_id', $user->id)->exists())->toBeTrue();
});

test('createRoom uses a supplied guest name instead of the "Guest" default', function () {
    Livewire::test('rooms-menu')
        ->set('guestName', 'Alex')
        ->call('createRoom')
        ->assertRedirect();

    $room = Room::sole();

    expect(User::find($room->host_user_id)->name)->toBe('Alex');
});

test('createRoom still defaults to "Guest" when no name is given', function () {
    Livewire::test('rooms-menu')
        ->call('createRoom')
        ->assertRedirect();

    $room = Room::sole();

    expect(User::find($room->host_user_id)->name)->toBe('Guest');
});

test('createRoom remembers a supplied guest name in a cookie for a later direct room-URL visit', function () {
    Livewire::test('rooms-menu')
        ->set('guestName', 'Alex')
        ->call('createRoom');

    $cookie = collect(Cookie::getQueuedCookies())
        ->first(fn ($cookie) => $cookie->getName() === 'guest_name');

    expect($cookie)->not->toBeNull()
        ->and($cookie->getValue())->toBe('Alex');
});

test('createRoom does not queue a guest-name cookie when no name was given', function () {
    Livewire::test('rooms-menu')->call('createRoom');

    $cookie = collect(Cookie::getQueuedCookies())
        ->first(fn ($cookie) => $cookie->getName() === 'guest_name');

    expect($cookie)->toBeNull();
});

test('joinByCode uses a supplied guest name instead of the "Guest" default', function () {
    // Room::factory()->create() already auto-hosts a member (see Room's
    // own static::created() hook), so the joining guest is a *second*
    // member here, not the only one.
    $room = Room::factory()->create();

    Livewire::test('rooms-menu')
        ->set('joinCode', $room->code)
        ->set('guestName', 'Sam')
        ->call('joinByCode')
        ->assertRedirect();

    expect($room->members()->where('user_id', '!=', $room->host_user_id)->sole()->user->name)->toBe('Sam');
});

test('joinByCode with an invalid code shows an error instead of redirecting', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('rooms-menu')
        ->set('joinCode', 'NOPE12')
        ->call('joinByCode')
        ->assertNoRedirect()
        ->assertSet('joinError', 'No active room with that code.');
});

test('myRooms only lists rooms the current user actually belongs to', function () {
    $user = User::factory()->create();
    $myRoom = Room::factory()->create();
    $myRoom->addMember($user);
    Room::factory()->create(); // someone else's room

    $list = Livewire::actingAs($user)->test('rooms-menu')->get('myRooms');

    expect($list)->toHaveCount(1)
        ->and($list->first()->room_id)->toBe($myRoom->id);
});

test('myRooms excludes expired rooms', function () {
    $user = User::factory()->create();
    $expired = Room::factory()->expired()->create();
    $expired->addMember($user);

    $list = Livewire::actingAs($user)->test('rooms-menu')->get('myRooms');

    expect($list)->toHaveCount(0);
});

/**
 * docs/concerns.md: "Room should not say 'finished' in sidebar" -- nothing
 * ever resets RoomStatus::Finished back to Lobby once a game ends (every
 * game's own endGame() only ever sets it forward), but the room is
 * immediately reusable regardless (the host can start another game in it
 * right away), so "Finished" read as though the room itself were over and
 * gone rather than just idle between games.
 */
test('a finished room shows as "Lobby" in the room list, not "Finished"', function () {
    $user = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $user->id, 'status' => RoomStatus::Finished]);

    $html = Livewire::actingAs($user)->test('rooms-menu')->html();

    expect($html)->toContain('Lobby')->not->toContain('Finished');
});

test('an active room still shows as "Active" in the room list', function () {
    $user = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $user->id, 'status' => RoomStatus::Active]);

    Livewire::actingAs($user)->test('rooms-menu')->assertSee('Active');
});

test('myRooms is empty for a logged-out visitor', function () {
    $list = Livewire::test('rooms-menu')->get('myRooms');

    expect($list)->toHaveCount(0);
});

/**
 * docs/concerns.md's "advancing from one game to the next" item: a member
 * who isn't sitting on the room hub or a game's own lobby at all -- browsing
 * some unrelated page -- still needs to find out the host moved on. This
 * component is mounted on every page (layouts/app.blade.php), so it's the
 * one place that can reach them regardless of what they're looking at.
 */
test('onGameSwitched pushes a dismissible notification linking straight to the game being set up (docs/concerns.md)', function () {
    $user = User::factory()->create();
    $room = Room::factory()->create();
    $room->addMember($user);

    $component = Livewire::actingAs($user)
        ->test('rooms-menu')
        ->call('onGameSwitched', ['type' => GameType::Farkle->value, 'code' => $room->code])
        ->assertSee(GameType::Farkle->label())
        ->assertSee($room->code);

    $notifications = $component->get('notifications');

    expect($notifications)->toHaveCount(1)
        ->and($notifications[0]['href'])->toBe(route(GameType::Farkle->roomRoute(), ['code' => $room->code]));

    $component->call('dismissNotification', $notifications[0]['id'])
        ->assertDontSee(GameType::Farkle->label());
});

test('onGameStarting pushes a dismissible notification linking straight into the active game (docs/concerns.md)', function () {
    $user = User::factory()->create();
    $room = Room::factory()->create();
    $room->addMember($user);
    $session = GameSession::factory()->create(['type' => GameType::Farkle]);
    $room->update(['game_session_id' => $session->id]);

    $component = Livewire::actingAs($user)
        ->test('rooms-menu')
        ->call('onGameStarting', ['code' => $room->code])
        ->assertSee($room->code);

    $notifications = $component->get('notifications');

    expect($notifications)->toHaveCount(1)
        ->and($notifications[0]['href'])->toBe(route(GameType::Farkle->playRoute(), ['room' => $room->code]));
});

test('onGameStarting falls back to the room hub link if the room has no active game session yet', function () {
    $user = User::factory()->create();
    $room = Room::factory()->create();
    $room->addMember($user);

    $component = Livewire::actingAs($user)
        ->test('rooms-menu')
        ->call('onGameStarting', ['code' => $room->code])
        ->assertSee($room->code);

    $notifications = $component->get('notifications');

    expect($notifications)->toHaveCount(1)
        ->and($notifications[0]['href'])->toBe(route('room.show', ['code' => $room->code]));
});

/**
 * docs/concerns.md: "Don't show join button if already in room as host" --
 * RoomGameSwitched/RoomGameStarting only ever broadcast because the room's
 * own host took the action (openLobby()/startGame() are both gated to the
 * host), and this component is mounted site-wide, so the host's own
 * connection always received their own broadcast right back -- confirmed
 * live, a host saw their own "Host is setting up X -- Join" toast pointing
 * at the exact page they were already on.
 */
test('onGameSwitched does not notify the room\'s own host of their own action', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    Livewire::actingAs($host)
        ->test('rooms-menu')
        ->call('onGameSwitched', ['type' => GameType::Farkle->value, 'code' => $room->code])
        ->assertSet('notifications', []);
});

test('onGameStarting does not notify the room\'s own host of their own action', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    Livewire::actingAs($host)
        ->test('rooms-menu')
        ->call('onGameStarting', ['code' => $room->code])
        ->assertSet('notifications', []);
});

test('a switch/start event for a room the user does not belong to is ignored', function () {
    $user = User::factory()->create();
    $otherRoom = Room::factory()->create();

    Livewire::actingAs($user)
        ->test('rooms-menu')
        ->call('onGameSwitched', ['type' => GameType::Farkle->value, 'code' => $otherRoom->code])
        ->assertSet('notifications', []);
});
