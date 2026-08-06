<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * docs/concerns.md #6: "Hitting home should keep you in the room, but not
 * in the game. If you are in a room, and have a seat in a game, but it's
 * not on the screen, going to that game should let you resume the game in
 * the same seat." Mostly already true once Phases B-D landed (mount() on
 * every game's play page re-resolves mySeatIndex fresh from RoomSeat on
 * every load, regardless of navigation path) -- this proves the actual
 * end-to-end path a user takes: leave to Home, come back via the room hub,
 * land in the same seat, not a fresh spectator/setup screen.
 */
it('keeps room membership after visiting Home, and resumes the same seat from the room hub', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $session = GameSession::create([
        'type' => GameType::Farkle,
        'mode' => GameMode::Online,
        'settings' => ['player_count' => 1, 'target_score' => 10000, 'with_final_round' => true],
        'state' => [
            'seats' => [['seatIndex' => 0, 'name' => $host->name, 'bankedScore' => 0, 'hasQualified' => false, 'farkleCount' => 0, 'hotDiceCount' => 0, 'bestTurnScore' => 0]],
            'currentSeat' => 0, 'dice' => [], 'selected' => [], 'bankedDiceThisTurn' => [],
            'turnScore' => 0, 'heldDiceCount' => 0, 'targetScore' => 10000, 'withFinalRound' => true,
            'finalRoundActive' => false, 'finalRoundRemaining' => null, 'gameOver' => false, 'message' => null, 'messageType' => null,
        ],
        'started_at' => now(),
    ]);
    $room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);
    RoomSeat::create(['room_id' => $room->id, 'seat_index' => 0, 'user_id' => $host->id]);

    $this->actingAs($host);

    // Arrive at the room hub directly (as if clicking it from the navbar
    // Rooms menu) -- this records membership.
    $page = visit('/room/'.$room->code);
    $page->assertSee('Resume your seat');

    // Leave to Home.
    $page->click('Home')->wait(0.3);
    $page->assertPathIs('/');

    // Still a member -- the room hub still renders instead of 404ing or
    // showing "not a member" of any kind.
    $page->navigate('/room/'.$room->code);
    $page->assertSee('Resume your seat');

    // Resuming actually lands back in the same seat (not spectating) --
    // the roll button, hidden entirely for a spectator, is visible.
    $page->click('Resume your seat')->wait(0.5);
    $page->assertPathIs('/farkle');
    $page->assertVisible('#rollBtn');
});
