<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('an unknown room code 404s', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/room/ZZZZZZ')->assertNotFound();
});

test('a logged-out visitor viewing a room directly is given a throwaway guest account and added as a member', function () {
    $room = Room::factory()->create();

    Livewire::test('pages::room.show', ['code' => $room->code])
        ->assertOk();

    expect($room->members()->count())->toBe(2); // host + the new guest
});

test('the member list shows every RoomMember, marking the host', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $guest = User::factory()->create();
    $room->addMember($guest);

    Livewire::actingAs($host)
        ->test('pages::room.show', ['code' => $room->code])
        ->assertSee($host->name)
        ->assertSee($guest->name)
        ->assertSee('(host)');
});

test('only the host can end the room', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $room->addMember($guest);

    Livewire::actingAs($guest)
        ->test('pages::room.show', ['code' => $room->code])
        ->call('endRoom')
        ->assertForbidden();

    expect(Room::find($room->id))->not->toBeNull();
});

test('the host ending the room deletes it and every member/seat', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $room->addMember($guest);
    RoomSeat::create(['room_id' => $room->id, 'seat_index' => 0, 'user_id' => $host->id]);

    Livewire::actingAs($host)
        ->test('pages::room.show', ['code' => $room->code])
        ->call('endRoom')
        ->assertRedirect();

    expect(Room::find($room->id))->toBeNull()
        ->and(RoomMember::where('room_id', $room->id)->count())->toBe(0)
        ->and(RoomSeat::where('room_id', $room->id)->count())->toBe(0);
});

test('a non-host can leave the room, removing only their own membership', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $room->addMember($guest);

    Livewire::actingAs($guest)
        ->test('pages::room.show', ['code' => $room->code])
        ->call('leaveRoom')
        ->assertRedirect();

    expect($room->members()->count())->toBe(1)
        ->and(Room::find($room->id))->not->toBeNull();
});

test('shows a link into the active game, distinguishing a seated member from a spectator', function () {
    $host = User::factory()->create();
    $spectator = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $session = GameSession::create([
        'type' => GameType::Farkle,
        'mode' => GameMode::Online,
        'settings' => [],
        'started_at' => now(),
    ]);
    $room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);
    RoomSeat::create(['room_id' => $room->id, 'seat_index' => 0, 'user_id' => $host->id]);
    $room->addMember($spectator);

    Livewire::actingAs($host)
        ->test('pages::room.show', ['code' => $room->code])
        ->assertSee('Resume your seat');

    Livewire::actingAs($spectator)
        ->test('pages::room.show', ['code' => $room->code])
        ->assertSee('Watch')
        ->assertDontSee('Resume your seat');
});

test('shows no active-game link once the room has finished', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id, 'status' => RoomStatus::Finished]);

    Livewire::actingAs($host)
        ->test('pages::room.show', ['code' => $room->code])
        ->assertDontSee('Resume your seat')
        ->assertDontSee('>Watch<', false);
});
