<?php

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The room hub's own "Start a new game" buttons used to be plain <a href>
 * links -- they got the host into a game's lobby, but broadcast nothing,
 * so any other member sitting on this same hub page had no way to follow
 * along (see pages::room.show's own startGame()/onGameSwitched() for the
 * fix). This covers the host's real click-through; the "another member
 * follows" half is covered at the Feature level in tests/Feature/
 * RoomShowTest.php, since this test env's BROADCAST_CONNECTION=null means
 * a second live browser page would never actually receive the broadcast.
 */
it('lets the host pick a game from the room hub and land in that game\'s real lobby', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $this->actingAs($host);

    $page = visit('/room/'.$room->code);
    $page->assertSee('Start a new game in this room')
        ->click('#start-game-farkle')
        ->wait(0.5);

    $page->assertPathIs('/10000/room/'.$room->code)
        ->assertSee('10,000 Room');

    expect($room->fresh()->game_session_id)->toBeNull();
});
