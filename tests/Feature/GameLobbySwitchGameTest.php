<?php

use App\Enums\GameType;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * <livewire:game-lobby> no longer offers its own "switch to a different
 * game" buttons -- those moved to pages::room.show exclusively
 * (docs/concerns.md: a host picking a different game to play belongs on
 * the room hub, not on every individual game's own setup/lobby page). See
 * tests/Feature/RoomShowTest.php for the host-check/session-reset/redirect/
 * broadcast coverage that used to live here against game-lobby's own
 * (now-removed) switchGame()/otherGames.
 *
 * What's left here is the one piece of behavior that's still game-lobby's
 * own regardless of *where* the switch was triggered from: reacting to the
 * room's game having changed while a member is sitting on a stale lobby.
 */
test('another present player sees a join prompt when the room switches games, without being redirected', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    // Written synchronously by the real pages::room.show::startGame(),
    // before it broadcasts -- see that method's own comment.
    $room->markPendingGame(GameType::Yahtzee);

    // Stands in for the real broadcast reaching a second connected
    // player's own game-lobby instance -- same convention as this app's
    // other presence-channel tests (e.g. GameLobbyTest's onReadyToggled
    // coverage), since a real Reverb broadcast needs a live connection
    // this test environment doesn't have (BROADCAST_CONNECTION=null).
    Livewire::actingAs($host)
        ->test('game-lobby', ['room' => $room, 'currentGame' => GameType::Boggle])
        ->call('onGameSwitched')
        ->assertNoRedirect()
        ->assertSee(GameType::Yahtzee->label());
});
