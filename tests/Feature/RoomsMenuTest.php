<?php

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

test('myRooms is empty for a logged-out visitor', function () {
    $list = Livewire::test('rooms-menu')->get('myRooms');

    expect($list)->toHaveCount(0);
});
