<?php

use App\Enums\GameType;
use App\Events\RoomGameSwitched;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Covers docs/TODO.md's former #1 ("decouple online rooms from specific
 * games"): a room's players should be able to stay in the same room (same
 * code, same seats) and move to a different game entirely, rather than
 * needing a brand new room. See docs/DEVLOG.md's "Room system" entry for
 * the full design.
 */
test('GameType::roomRoute() maps every case to its own room route name', function () {
    expect(GameType::Boggle->roomRoute())->toBe('boggle.room')
        ->and(GameType::Yahtzee->roomRoute())->toBe('yahtzee.room')
        ->and(GameType::FamilyFeud->roomRoute())->toBe('family-feud.room')
        ->and(GameType::Farkle->roomRoute())->toBe('farkle.room');
});

test('otherGames excludes only the current game', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $component = Livewire::actingAs($host)
        ->test('game-lobby', ['room' => $room, 'currentGame' => GameType::Boggle]);

    expect($component->get('otherGames'))->toBe([GameType::Yahtzee, GameType::FamilyFeud, GameType::Farkle]);
});

test('the host can switch the room to a different game', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create([
        'host_user_id' => $host->id,
        'game_session_id' => GameSession::factory()->create(['type' => GameType::Boggle])->id,
    ]);

    Livewire::actingAs($host)
        ->test('game-lobby', ['room' => $room, 'currentGame' => GameType::Boggle])
        ->call('switchGame', GameType::Yahtzee->value)
        ->assertRedirect(route('yahtzee.room', ['code' => $room->code]));

    // Reset exactly like startGame() resets it for a same-game replay --
    // whichever game gets started next (in the room being switched to, or
    // back in this one later) must generate a genuinely fresh session
    // rather than silently reusing this now-stale Boggle one.
    expect($room->fresh()->game_session_id)->toBeNull();
});

test('a non-host cannot switch the game', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    Livewire::actingAs($guest)
        ->test('game-lobby', ['room' => $room, 'currentGame' => GameType::Boggle])
        ->call('switchGame', GameType::Yahtzee->value)
        ->assertForbidden();
});

test('switching games broadcasts RoomGameSwitched with the target game type', function () {
    Event::fake([RoomGameSwitched::class]);

    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    Livewire::actingAs($host)
        ->test('game-lobby', ['room' => $room, 'currentGame' => GameType::Boggle])
        ->call('switchGame', GameType::FamilyFeud->value);

    Event::assertDispatched(RoomGameSwitched::class, function (RoomGameSwitched $event) use ($room) {
        return $event->room->is($room) && $event->type === GameType::FamilyFeud;
    });
});

test('another present player is redirected when the game is switched', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    // Stands in for the real broadcast reaching a second connected
    // player's own game-lobby instance -- same convention as this app's
    // other presence-channel tests (e.g. GameLobbyTest's onReadyToggled
    // coverage), since a real Reverb broadcast needs a live connection
    // this test environment doesn't have (BROADCAST_CONNECTION=null).
    Livewire::actingAs($host)
        ->test('game-lobby', ['room' => $room, 'currentGame' => GameType::Boggle])
        ->call('onGameSwitched', ['type' => GameType::Yahtzee->value])
        ->assertRedirect(route('yahtzee.room', ['code' => $room->code]));
});

test('switching to a game clears seats left over from whichever game was played before', function () {
    // Regression coverage for the exact bug the underlying RoomSeat fix
    // (see pages::yahtzee.room and pages::family-feud.room) guards
    // against, now exercised through a real cross-game switch rather than
    // a same-game replay: Yahtzee's own seat rows must not collide with
    // (or survive alongside) Family Feud's once the room switches games.
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    RoomSeat::factory()->create(['room_id' => $room->id, 'seat_index' => 0, 'user_id' => $host->id]);
    RoomSeat::factory()->create(['room_id' => $room->id, 'seat_index' => 1, 'user_id' => $guest->id]);

    Livewire::actingAs($host)
        ->test('game-lobby', ['room' => $room, 'currentGame' => GameType::Yahtzee])
        ->call('switchGame', GameType::FamilyFeud->value);

    expect(RoomSeat::where('room_id', $room->id)->count())->toBe(2);
});

test('a room can switch games repeatedly without accumulating stale state', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    foreach ([GameType::Yahtzee, GameType::FamilyFeud, GameType::Boggle, GameType::Yahtzee] as $target) {
        $current = Room::find($room->id)->gameSession?->type ?? GameType::Boggle;

        Livewire::actingAs($host)
            ->test('game-lobby', ['room' => $room, 'currentGame' => $current])
            ->call('switchGame', $target->value)
            ->assertRedirect(route($target->roomRoute(), ['code' => $room->code]));

        expect($room->fresh()->game_session_id)->toBeNull();
    }
});
