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
 * The between-games flow end to end in a real browser -- the follow
 * countdown is plain Alpine (a real setInterval + window.location), which
 * Livewire::test() can't run at all. One browser page per test, other
 * seats seeded at the database level (see CLAUDE.md's browser-testing
 * gotchas).
 */
it('takes a non-host from the room hub to the newly opened lobby after the countdown', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $room->addMember($guest);
    $room->openLobby(GameType::Farkle);

    $this->actingAs($guest);

    $page = visit('/room/'.$room->code);

    $page->assertSee('The host is setting up 10,000')
        ->assertSee('Taking you there in')
        ->wait(11)
        ->assertPathIs('/10000/room/'.$room->code)
        ->assertNoJavaScriptErrors();
});

it('keeps a non-host where they are after Stay here, even across a reload', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $room->addMember($guest);
    $room->openLobby(GameType::Farkle);

    $this->actingAs($guest);

    $page = visit('/room/'.$room->code);

    $page->assertSee('Taking you there in')
        ->click('#room-follow-stay')
        ->wait(11)
        ->assertPathIs('/room/'.$room->code)
        ->assertMissing('#room-follow-stay')
        ->assertVisible('#room-follow-join');

    $page->refresh()
        ->wait(2)
        ->assertPathIs('/room/'.$room->code)
        ->assertMissing('#room-follow-stay');
});

it('lets the host Play again from the results screen into an open lobby, then back to settings', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $room->openLobby(GameType::Farkle, ['targetScore' => 15000, 'withFinalRound' => true, 'spicyEnabled' => false, 'highStakesEnabled' => false]);

    $session = GameSession::create([
        'type' => GameType::Farkle,
        'mode' => GameMode::Online,
        'settings' => ['player_count' => 1, 'target_score' => 15000, 'with_final_round' => true],
        'state' => [
            'seats' => [['seatIndex' => 0, 'name' => $host->name, 'bankedScore' => 15200, 'hasQualified' => true, 'farkleCount' => 0, 'hotDiceCount' => 0, 'bestTurnScore' => 1000]],
            'currentSeat' => 0, 'dice' => [], 'selected' => [], 'bankedDiceThisTurn' => [],
            'turnScore' => 0, 'heldDiceCount' => 0, 'targetScore' => 15000, 'withFinalRound' => true,
            'finalRoundActive' => false, 'finalRoundRemaining' => null, 'gameOver' => true,
            'message' => 'Game Over', 'messageType' => 'gameOver',
        ],
        'started_at' => now(),
    ]);
    $room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);
    RoomSeat::create(['room_id' => $room->id, 'seat_index' => 0, 'user_id' => $host->id]);
    $room->finishCurrentGame($session->id);

    $this->actingAs($host);

    $page = visit('/10000?room='.$room->code);

    $page->assertSee("Game over -- what's next?")
        ->click('#play-again-btn')
        ->assertPathIs('/10000/room/'.$room->code)
        ->assertSee("I'm ready")
        ->click('#change-settings-btn')
        ->assertSee('Open Lobby')
        ->assertNoJavaScriptErrors();

    expect($page->script("document.getElementById('target-score-15000').classList.contains('active')"))->toBeTrue();
});
