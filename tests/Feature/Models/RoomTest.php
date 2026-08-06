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

test('the host automatically becomes a member the instant a room is created', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    expect($room->members()->count())->toBe(1)
        ->and($room->members()->first()->user_id)->toBe($host->id);
});

test('addMember is idempotent -- calling it twice for the same user does not duplicate', function () {
    $room = Room::factory()->create();
    $user = User::factory()->create();

    $first = $room->addMember($user);
    $second = $room->addMember($user);

    expect($first->id)->toBe($second->id)
        ->and($room->members()->where('user_id', $user->id)->count())->toBe(1);
});

test('addMember records a distinct member per user', function () {
    $room = Room::factory()->create();
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $room->addMember($userA);
    $room->addMember($userB);

    // 3: the host (auto-added on creation) plus these two.
    expect($room->members()->count())->toBe(3);
});
