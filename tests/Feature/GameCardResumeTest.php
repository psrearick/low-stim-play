<?php

use App\Enums\GameType;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * docs/concerns.md #7: a logged-in visitor already in a room for a given
 * game should be able to resume their seat right from that game's own home
 * page card, without detouring through the room hub first.
 */
test('a guest sees no resume link', function () {
    $component = Livewire::test('game-card-resume', ['type' => GameType::Farkle]);

    expect($component->get('resumable'))->toBeNull();
});

test('a logged-in user with no rooms sees no resume link', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test('game-card-resume', ['type' => GameType::Farkle]);

    expect($component->get('resumable'))->toBeNull();
});

test('a seated player in an active game of this type gets a resume link to the play page', function () {
    $user = User::factory()->create();
    $room = Room::factory()->active()->create();
    $room->addMember($user);
    $session = GameSession::factory()->create(['type' => GameType::Farkle]);
    $room->update(['game_session_id' => $session->id]);
    RoomSeat::factory()->create(['room_id' => $room->id, 'seat_index' => 0, 'user_id' => $user->id]);

    $component = Livewire::actingAs($user)->test('game-card-resume', ['type' => GameType::Farkle])
        ->assertSee('Resume your seat');

    $resumable = $component->get('resumable');
    expect($resumable['room']->id)->toBe($room->id)
        ->and($resumable['active'])->toBeTrue();
});

test('a room member with no seat in an active seat-based game gets no resume link', function () {
    $user = User::factory()->create();
    $room = Room::factory()->active()->create();
    $room->addMember($user);
    $session = GameSession::factory()->create(['type' => GameType::Farkle]);
    $room->update(['game_session_id' => $session->id]);
    // No RoomSeat created for $user -- e.g. joined after the game started.

    $component = Livewire::actingAs($user)->test('game-card-resume', ['type' => GameType::Farkle]);

    expect($component->get('resumable'))->toBeNull();
});

test('Boggle resumes on room membership alone, since it has no RoomSeat concept', function () {
    $user = User::factory()->create();
    $room = Room::factory()->active()->create();
    $room->addMember($user);
    $session = GameSession::factory()->create(['type' => GameType::Boggle]);
    $room->update(['game_session_id' => $session->id]);

    $component = Livewire::actingAs($user)->test('game-card-resume', ['type' => GameType::Boggle])
        ->assertSee('Resume your seat');

    expect($component->get('resumable')['active'])->toBeTrue();
});

test('a member of a room the host is still setting up gets a "Continue setup" link to the room lobby', function () {
    $user = User::factory()->create();
    $room = Room::factory()->create();
    $room->addMember($user);
    $room->markPendingGame(GameType::Yahtzee);

    $component = Livewire::actingAs($user)->test('game-card-resume', ['type' => GameType::Yahtzee])
        ->assertSee('Continue setup');

    $resumable = $component->get('resumable');
    expect($resumable['room']->id)->toBe($room->id)
        ->and($resumable['active'])->toBeFalse();
});

test('a mismatched game type gets no resume link', function () {
    $user = User::factory()->create();
    $room = Room::factory()->active()->create();
    $room->addMember($user);
    $session = GameSession::factory()->create(['type' => GameType::Farkle]);
    $room->update(['game_session_id' => $session->id]);
    RoomSeat::factory()->create(['room_id' => $room->id, 'seat_index' => 0, 'user_id' => $user->id]);

    $component = Livewire::actingAs($user)->test('game-card-resume', ['type' => GameType::Yahtzee]);

    expect($component->get('resumable'))->toBeNull();
});

test('an expired room is never offered as resumable', function () {
    $user = User::factory()->create();
    $room = Room::factory()->active()->expired()->create();
    $room->addMember($user);
    $session = GameSession::factory()->create(['type' => GameType::Farkle]);
    $room->update(['game_session_id' => $session->id]);
    RoomSeat::factory()->create(['room_id' => $room->id, 'seat_index' => 0, 'user_id' => $user->id]);

    $component = Livewire::actingAs($user)->test('game-card-resume', ['type' => GameType::Farkle]);

    expect($component->get('resumable'))->toBeNull();
});

test('room-less games (e.g. Word Blocks) never show a resume link', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test('game-card-resume', ['type' => GameType::Quartiles]);

    expect($component->get('resumable'))->toBeNull();
});
