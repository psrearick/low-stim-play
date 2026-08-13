<?php

use App\Enums\ClearTheDeckPhase;
use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regression coverage for docs/concerns.md's "Back to Room" report: on a
 * narrow phone, Clear the Deck's own "Back to room" button (teleported into
 * .button-bar .right alongside <livewire:rooms-menu/>) rendered 63px past
 * the right edge of the viewport -- fully clipped off-screen, not just
 * touching the edge (confirmed live via getBoundingClientRect()).
 *
 * Root cause: .button-bar .right only wrapped onto a second line instead of
 * overflowing once a game's own JS added the .many-buttons class, and only
 * Farkle and Yahtzee ever did that (see FarkleMobileLayoutTest.php's own
 * "crowded online-room" case). Boggle, Family Feud, Apple Match, and Clear
 * the Deck never opted in, so all four were equally exposed any time their
 * online-room .right grew past one line's worth of width. Fixed in
 * app.css by making .right's flex-wrap unconditional (see the CSS's own
 * comment) rather than requiring each game to remember a JS opt-in -- this
 * test picks Clear the Deck specifically since it's the one actually
 * reported, and because (unlike Farkle/Yahtzee) it never had any
 * .many-buttons handling to fall back on.
 */
it('keeps the "Back to room" button on screen on a narrow phone at game end', function () {
    $host = User::factory()->create();
    $guest1 = User::factory()->create();
    $guest2 = User::factory()->create();

    $room = Room::factory()->active()->create(['host_user_id' => $host->id]);

    $session = GameSession::factory()->create([
        'type' => GameType::ClearTheDeck,
        'mode' => GameMode::Online,
        'settings' => ['round_target' => 1],
        'state' => [
            'roundTarget' => 1,
            'currentRound' => 1,
            'seats' => [
                ['seatIndex' => 0, 'name' => $host->name],
                ['seatIndex' => 1, 'name' => $guest1->name],
                ['seatIndex' => 2, 'name' => $guest2->name],
            ],
            'scores' => [10, 20, 30],
            'roundsWonCounts' => [1, 0, 0],
            'dealerSeatIndex' => 0,
            'turnSeatIndex' => 1,
            'centerStack' => [],
            'phase' => ClearTheDeckPhase::GameEnd->value,
            'lastMessage' => null,
            'roundScores' => [],
            'wentOutSeatIndex' => 0,
            'winnerName' => $host->name,
            'seatTables' => [
                0 => ['handCount' => 0, 'tableFaceUp' => [], 'tableFaceDownCount' => 0],
                1 => ['handCount' => 5, 'tableFaceUp' => [7], 'tableFaceDownCount' => 2],
                2 => ['handCount' => 3, 'tableFaceUp' => [2], 'tableFaceDownCount' => 1],
            ],
        ],
    ]);

    $room->update(['game_session_id' => $session->id]);

    RoomSeat::factory()->create(['room_id' => $room->id, 'seat_index' => 0, 'user_id' => $host->id]);
    RoomSeat::factory()->create(['room_id' => $room->id, 'seat_index' => 1, 'user_id' => $guest1->id]);
    RoomSeat::factory()->create(['room_id' => $room->id, 'seat_index' => 2, 'user_id' => $guest2->id]);

    $this->actingAs($host);

    $page = visit('/clear-the-deck?room='.$room->code)->resize(390, 844);
    $page->assertSee('Back to room');

    $info = $page->script(
        <<<'JS'
        (() => {
            const link = [...document.querySelectorAll('#game-actions a, #game-actions button')]
                .find((el) => el.textContent.trim() === 'Back to room');
            const rect = link.getBoundingClientRect();
            return { right: rect.right, left: rect.left, viewport: window.innerWidth };
        })()
        JS
    );

    expect($info['left'])->toBeGreaterThanOrEqual(0);
    expect($info['right'])->toBeLessThanOrEqual($info['viewport']);
});
