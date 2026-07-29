<?php

use App\Enums\RoomStatus;
use App\Events\RoomGameStarting;
use App\Events\RoomPlayerReadyToggled;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('lobby tracks players announced via presence events', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    Livewire::actingAs($host)
        ->test('game-lobby', ['room' => $room])
        ->call('onHere', [['id' => $host->id, 'name' => $host->name, 'is_host' => true]])
        ->assertSet("players.{$host->id}.ready", false)
        ->assertSee($host->name);
});

test('toggling ready updates local state and broadcasts an event', function () {
    $user = User::factory()->create();
    $room = Room::factory()->create();

    Event::fake();

    Livewire::actingAs($user)
        ->test('game-lobby', ['room' => $room])
        ->call('onJoining', ['id' => $user->id, 'name' => $user->name, 'is_host' => false])
        ->call('toggleReady')
        ->assertSet("players.{$user->id}.ready", true);

    Event::assertDispatched(
        RoomPlayerReadyToggled::class,
        fn (RoomPlayerReadyToggled $event) => $event->userId === $user->id && $event->ready === true,
    );
});

test('the host cannot start the game until everyone is ready', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    Livewire::actingAs($host)
        ->test('game-lobby', ['room' => $room])
        ->call('onJoining', ['id' => $host->id, 'name' => $host->name, 'is_host' => true])
        ->call('onJoining', ['id' => $guest->id, 'name' => $guest->name, 'is_host' => false])
        ->call('startGame')
        ->assertForbidden();

    expect($room->fresh()->status)->toBe(RoomStatus::Lobby);
});

test('the host can start the game once everyone is ready', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    Event::fake();

    Livewire::actingAs($host)
        ->test('game-lobby', ['room' => $room])
        ->call('onJoining', ['id' => $host->id, 'name' => $host->name, 'is_host' => true])
        ->call('onJoining', ['id' => $guest->id, 'name' => $guest->name, 'is_host' => false])
        ->call('onReadyToggled', ['user_id' => $host->id, 'ready' => true])
        ->call('onReadyToggled', ['user_id' => $guest->id, 'ready' => true])
        ->call('startGame');

    Event::assertDispatched(RoomGameStarting::class);
    expect($room->fresh()->status)->toBe(RoomStatus::Active);
});

test('a non-host cannot start the game', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    Livewire::actingAs($guest)
        ->test('game-lobby', ['room' => $room])
        ->call('startGame')
        ->assertForbidden();
});
