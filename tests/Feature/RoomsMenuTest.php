<?php

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
