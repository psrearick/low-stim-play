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
 * A spicy die's highlight (docs/specs/farkle.md §6, the ".die.spicy" rule
 * in app.css) is pure CSS -- exactly the kind of thing a Livewire-level
 * test can't see (CLAUDE.md's "Automated Browser Testing" section), so
 * this confirms the real rendered border color, not just that the "spicy"
 * class string is present in the markup. State is constructed directly in
 * the database (mirroring FarkleBackToRoomTest's own pattern) rather than
 * relying on a real roll landing all six dice spicy, which happens far too
 * rarely ((1/6)^6) to reach live.
 */
it('renders a spicy die with a visibly different border than a plain die', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $session = GameSession::create([
        'type' => GameType::Farkle,
        'mode' => GameMode::Online,
        'settings' => ['player_count' => 1, 'target_score' => 25000, 'with_final_round' => true],
        'state' => [
            'seats' => [['seatIndex' => 0, 'name' => $host->name, 'bankedScore' => 0, 'hasQualified' => false, 'farkleCount' => 0, 'hotDiceCount' => 0, 'bestTurnScore' => 0]],
            'currentSeat' => 0,
            'dice' => [4, 4, 4, 2, 1, 3],
            'selected' => [],
            'dieSpicy' => [false, false, true, false, false, false], // the third 4 is spicy
            'activeDieSlots' => [1, 2, 3, 4, 5, 6],
            'lastBankInheritance' => null,
            'bankedDiceThisTurn' => [],
            'turnScore' => 0, 'heldDiceCount' => 0, 'targetScore' => 25000, 'withFinalRound' => true,
            'spicyEnabled' => true, 'highStakesEnabled' => false,
            'finalRoundActive' => false, 'finalRoundRemaining' => null, 'gameOver' => false, 'message' => null, 'messageType' => null,
        ],
        'started_at' => now(),
    ]);
    $room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);
    RoomSeat::create(['room_id' => $room->id, 'seat_index' => 0, 'user_id' => $host->id]);

    $this->actingAs($host);

    $page = visit('/10000?room='.$room->code);
    $page->assertSee('Bank');

    $spicyDieCount = $page->script("document.querySelectorAll('.farkle-play .die.spicy').length");
    expect($spicyDieCount)->toBe(1);

    $spicyBorder = $page->script("getComputedStyle(document.querySelector('.farkle-play .die.spicy')).borderColor");
    $plainBorder = $page->script("getComputedStyle(document.querySelectorAll('.farkle-play .die:not(.spicy)')[0]).borderColor");

    expect($spicyBorder)->not->toBe($plainBorder);
});

it('shows the Spicy and High Stakes setup toggles, and Spicy defaults the target score to 25,000', function () {
    $page = visit('/10000');

    $page->assertSee('Spicy')->assertSee('High Stakes')
        ->assertSee('10,000') // the plain-mode default target score option
        ->click('#spicy-on')
        ->assertSee('25,000'); // the Spicy-mode target score options replace the plain set
});
