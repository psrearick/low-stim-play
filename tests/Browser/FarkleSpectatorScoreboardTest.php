<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * docs/concerns.md's "advancing from one game to the next" item
 * generalizes Clear the Deck's own no-scrolling-while-spectating fix to
 * every game with a central spectator view. Farkle's scoreboard used to be
 * a single tall column with a fixed min-height/overflow-y-auto (built for
 * a seated player's own device, where scrolling past it is normal) --
 * confirmed live that this cut several players off entirely on a
 * TV/tablet-width viewport. Spectating now renders a cqh-sized grid
 * instead (see app.css's .farkle-spectator-grid), which fills whatever
 * height .farkle-scoreboard actually has rather than needing to scroll
 * past it.
 */
test('the watch-only scoreboard fits an 8-player game on a tablet-width viewport with no scrolling', function () {
    $host = User::factory()->create();
    $players = collect(range(0, 7))->map(fn ($i) => [
        'seatIndex' => $i,
        'name' => 'Player '.($i + 1),
        'bankedScore' => $i * 350,
        'hasQualified' => true,
        'farkleCount' => 0,
        'hotDiceCount' => 0,
        'bestTurnScore' => 0,
    ])->all();

    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $session = GameSession::create([
        'type' => GameType::Farkle,
        'mode' => GameMode::Online,
        'settings' => ['player_count' => 8, 'target_score' => 10000, 'with_final_round' => true],
        'state' => [
            'seats' => $players, 'currentSeat' => 2, 'dice' => [1, 2, 3, 4, 5, 6],
            'selected' => [], 'bankedDiceThisTurn' => [], 'turnScore' => 0, 'heldDiceCount' => 0,
            'targetScore' => 10000, 'withFinalRound' => true, 'finalRoundActive' => false,
            'finalRoundRemaining' => null, 'gameOver' => false, 'message' => null, 'messageType' => null,
        ],
        'started_at' => now(),
    ]);

    $room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);

    $spectator = User::factory()->create();
    $this->actingAs($spectator);

    $page = visit('/10000?room='.$room->code);
    $page->assertSee('Player 1');

    $page->resize(1024, 768)->wait(0.2);

    $page->assertVisible('.farkle-spectator-grid');

    foreach ($players as $player) {
        $page->assertSee($player['name']);
    }

    $overflow = $page->script(
        '({ x: document.documentElement.scrollWidth - window.innerWidth, y: document.documentElement.scrollHeight - window.innerHeight })'
    );

    expect($overflow['x'])->toBeLessThanOrEqual(0)
        ->and($overflow['y'])->toBeLessThanOrEqual(0);
});
