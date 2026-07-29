<?php

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a room is created with a unique 6-character code and an expiry', function () {
    $room = Room::factory()->create();

    expect($room->code)->toHaveLength(6)
        ->and($room->status)->toBe(RoomStatus::Lobby)
        ->and($room->expires_at)->not->toBeNull()
        ->and($room->expires_at->isFuture())->toBeTrue();
});

test('room codes exclude visually ambiguous characters', function () {
    $codes = collect(range(1, 25))->map(fn () => Room::generateUniqueCode());

    expect($codes->every(fn (string $code) => ! preg_match('/[01OI]/', $code)))->toBeTrue();
});

test('a room belongs to a host', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    expect($room->host->is($host))->toBeTrue();
});

test('notExpired scope excludes expired rooms', function () {
    Room::factory()->expired()->create();
    $active = Room::factory()->create();

    expect(Room::notExpired()->get())->toHaveCount(1)
        ->and(Room::notExpired()->first()->is($active))->toBeTrue();
});
