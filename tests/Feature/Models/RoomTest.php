<?php

use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Events\RoomGameStateUpdated;
use App\Models\GameSession;
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

test('pendingGameType is null until markPendingGame is called', function () {
    $room = Room::factory()->create();

    expect($room->pendingGameType())->toBeNull();
});

test('markPendingGame records which game the host is currently setting up', function () {
    $room = Room::factory()->create();

    $room->markPendingGame(GameType::AppleMatch);

    expect($room->pendingGameType())->toBe(GameType::AppleMatch);
});

test('markPendingGame for a different game overwrites the previous one, not stacks', function () {
    $room = Room::factory()->create();

    $room->markPendingGame(GameType::Boggle);
    $room->markPendingGame(GameType::AppleMatch);

    expect($room->pendingGameType())->toBe(GameType::AppleMatch);
});

test('pendingGameType is null once a real, active game session exists, even with a stale marker', function () {
    $room = Room::factory()->create();
    $room->markPendingGame(GameType::AppleMatch);

    $session = GameSession::factory()->create(['type' => GameType::AppleMatch]);
    $room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);

    expect($room->fresh()->pendingGameType())->toBeNull();
});

/**
 * docs/concerns.md, 2026-08-29: a room whose most recent game already
 * ended still has `game_session_id` pointing at that finished session
 * (every game's own endGame() only ever sets `status` to
 * RoomStatus::Finished, never clears the id -- see Room::pendingGameType()'s
 * own docblock) -- reopening a lobby in that same room must not have the
 * stale id block markPendingGame() from taking effect, or the host's own
 * <livewire:game-lobby> immediately reverts to the "Open Lobby" button and
 * every other member sees "Waiting for the host" no matter how many times
 * the host actually opens it.
 */
test('pendingGameType is NOT null when game_session_id points at an already-finished game', function () {
    $room = Room::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::AppleMatch]);
    $room->update(['status' => RoomStatus::Finished, 'game_session_id' => $session->id]);

    $room->markPendingGame(GameType::Hoax);

    expect($room->fresh()->pendingGameType())->toBe(GameType::Hoax);
});

test('extendExpiry resets expiry to a full LIFETIME_MINUTES from now, not just adding time to whatever it already was', function () {
    $room = Room::factory()->create(['expires_at' => now()->addMinute()]);

    $room->extendExpiry();

    expect($room->fresh()->expires_at->diffInMinutes(now(), true))
        ->toBeGreaterThan(Room::LIFETIME_MINUTES - 1)
        ->toBeLessThanOrEqual(Room::LIFETIME_MINUTES);
});

test('extendExpiry can revive a room that had already expired', function () {
    $room = Room::factory()->expired()->create();
    expect(Room::notExpired()->where('id', $room->id)->exists())->toBeFalse();

    $room->extendExpiry();

    expect(Room::notExpired()->where('id', $room->id)->exists())->toBeTrue();
});

test('dispatching RoomGameStateUpdated automatically extends the room\'s expiry -- the single fix point shared by every room game', function () {
    $room = Room::factory()->create(['expires_at' => now()->addMinute()]);

    RoomGameStateUpdated::dispatch($room, ['phase' => 'anything']);

    expect($room->fresh()->expires_at->diffInMinutes(now(), true))->toBeGreaterThan(Room::LIFETIME_MINUTES - 1);
});
