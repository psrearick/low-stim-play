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
 * Coverage audit gap (see docs/DEVLOG.md's Boggle "New Game" bug root
 * cause): Farkle's own "Watch only"/"Player view" toggle and its "Scores"
 * drawer button -- both teleported into #game-actions alongside End Game/
 * New Game/Back to room -- had never actually been clicked in a real
 * browser, only asserted absent for a spectator in unrelated tests.
 */
function startedFarkleRoom(int $seatCount = 2): array
{
    $users = User::factory()->count($seatCount)->create();
    $room = Room::factory()->create(['host_user_id' => $users->first()->id]);

    $session = GameSession::create([
        'type' => GameType::Farkle,
        'mode' => GameMode::Online,
        'settings' => ['player_count' => $seatCount, 'target_score' => 10000, 'with_final_round' => true],
        'state' => [
            'seats' => $users->values()->map(fn (User $user, int $i) => [
                'seatIndex' => $i, 'name' => $user->name, 'bankedScore' => 0,
                'hasQualified' => false, 'farkleCount' => 0, 'hotDiceCount' => 0, 'bestTurnScore' => 0,
            ])->all(),
            'currentSeat' => 0, 'dice' => [], 'selected' => [], 'bankedDiceThisTurn' => [],
            'turnScore' => 0, 'heldDiceCount' => 0, 'targetScore' => 10000, 'withFinalRound' => true,
            'finalRoundActive' => false, 'finalRoundRemaining' => null, 'gameOver' => false, 'message' => null, 'messageType' => null,
        ],
        'started_at' => now(),
    ]);
    $room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);

    foreach ($users as $i => $user) {
        RoomSeat::create(['room_id' => $room->id, 'seat_index' => $i, 'user_id' => $user->id]);
    }

    return [$room, $session, $users];
}

it('toggles Watch only mid-turn to a read-only view, and back', function () {
    [$room, , $users] = startedFarkleRoom();

    $this->actingAs($users->first());

    $page = visit('/10000?room='.$room->code);

    $page->assertVisible('#rollBtn')
        ->click('Watch only')
        ->wait(0.3);

    $page->assertMissing('#rollBtn')
        ->assertSee('Player view');

    $page->click('Player view')->wait(0.3);

    $page->assertVisible('#rollBtn')
        ->assertSee('Watch only');
});

it('opens the Scores drawer showing every seat', function () {
    [$room, , $users] = startedFarkleRoom();

    $this->actingAs($users->first());

    $page = visit('/10000?room='.$room->code);

    $page->assertVisible('#scores-btn')
        ->click('#scores-btn')
        ->wait(0.3);

    $page->assertVisible('#scoreboard-drawer')
        ->assertSee($users->first()->name)
        ->assertSee($users->last()->name);
});
