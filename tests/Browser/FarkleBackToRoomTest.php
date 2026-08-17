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
 * docs/concerns.md: "Back to room button does not do anything on game over
 * screen, maybe in other games too." Root cause (confirmed via a real
 * scripted browser session, not just reading the code -- see CLAUDE.md's
 * browser-testing rules): ending a game never changed Room.status/
 * game_session_id away from what startGame() left them as ('active',
 * pointing at the now-finished session), so pages::farkle.room's own
 * redirectIfAlreadyStarted() -- meant to bounce a *reconnecting mid-game*
 * player straight back into their seat -- couldn't tell "still playing"
 * from "already over," and silently redirected right back to the game the
 * user had just clicked "Back to room" to leave. Fixed by finishGame()
 * marking the room RoomStatus::Finished once an online game actually ends.
 *
 * "Back to room" itself now points at the game-agnostic room hub
 * (pages::room.show), not this game's own lobby -- see docs/concerns.md's
 * "advancing from one game to the next" item -- so this also covers that
 * routing change: the hub reads Room.status/game_session_id directly
 * rather than needing redirectIfAlreadyStarted() to behave at all.
 */
it('actually navigates back to the room after the host ends the game', function () {
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

    $page = visit('/10000?room='.$room->code);
    $page->assertSee('Roll')
        ->click('#end-game-trigger')
        ->assertSee('End the game?')
        ->click('#end-game-confirm')
        ->assertSee('Back to room')
        ->click('Back to room')
        ->assertPathIs('/room/'.$room->code)
        ->assertSee('Start a new game in this room');

    expect($room->fresh()->status)->toBe(RoomStatus::Finished);
});
