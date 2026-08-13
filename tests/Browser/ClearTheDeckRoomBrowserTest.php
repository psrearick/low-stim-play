<?php

use App\Enums\ClearTheDeckPhase;
use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Pest\Browser\Api\PendingAwaitablePage;

uses(RefreshDatabase::class);

/**
 * Same single-real-browser-plus-DB-seeded-state convention as
 * tests/Browser/AppleMatchRoomBrowserTest.php -- see that file's own
 * docblock for why. Three seats (the game's own minimum, see
 * docs/specs/clear-the-deck.md) with seat 1 (guest1) holding the turn, so
 * this covers active-player vs. waiting vs. spectator visibility --
 * tests/Feature/Games/ClearTheDeckRoomTest.php already covers the turn
 * engine's authorization logic in depth.
 *
 * @return array{0: Room, 1: GameSession, 2: Collection<int, User>}
 */
function startedClearTheDeckRoom(): array
{
    $host = User::factory()->create(['password' => bcrypt('password')]);
    $guest1 = User::factory()->create(['password' => bcrypt('password')]);
    $guest2 = User::factory()->create(['password' => bcrypt('password')]);
    $users = collect([$host, $guest1, $guest2]);

    $room = Room::factory()->active()->create(['host_user_id' => $host->id]);

    $state = [
        'roundTarget' => 1,
        'currentRound' => 1,
        'seats' => [
            ['seatIndex' => 0, 'name' => $host->name],
            ['seatIndex' => 1, 'name' => $guest1->name],
            ['seatIndex' => 2, 'name' => $guest2->name],
        ],
        'scores' => [0, 0, 0],
        'roundsWonCounts' => [0, 0, 0],
        'dealerSeatIndex' => 0,
        'turnSeatIndex' => 1,
        'centerStack' => [],
        'phase' => ClearTheDeckPhase::Playing->value,
        'lastMessage' => null,
        'roundScores' => [],
        'wentOutSeatIndex' => null,
        'winnerName' => null,
        'seatTables' => [
            0 => ['handCount' => 12, 'tableFaceUp' => [3, 5], 'tableFaceDownCount' => 4],
            1 => ['handCount' => 12, 'tableFaceUp' => [7, 9], 'tableFaceDownCount' => 4],
            2 => ['handCount' => 12, 'tableFaceUp' => [2, 6], 'tableFaceDownCount' => 4],
        ],
    ];

    $session = GameSession::factory()->create([
        'type' => GameType::ClearTheDeck,
        'mode' => GameMode::Online,
        'settings' => ['round_target' => 1],
        'state' => $state,
    ]);

    $room->update(['game_session_id' => $session->id]);

    RoomSeat::factory()->create(['room_id' => $room->id, 'seat_index' => 0, 'user_id' => $host->id, 'hand' => range(1, 12), 'table_face_up' => [3, 5], 'table_face_down' => [1, 4, 8, 11]]);
    RoomSeat::factory()->create(['room_id' => $room->id, 'seat_index' => 1, 'user_id' => $guest1->id, 'hand' => range(1, 12), 'table_face_up' => [7, 9], 'table_face_down' => [2, 4, 6, 8]]);
    RoomSeat::factory()->create(['room_id' => $room->id, 'seat_index' => 2, 'user_id' => $guest2->id, 'hand' => range(1, 12), 'table_face_up' => [2, 6], 'table_face_down' => [3, 5, 7, 9]]);

    return [$room, $session, $users];
}

/**
 * A real login through the actual form -- not actingAs(), which only sets
 * the container's resolved guard user and never touches a real session
 * cookie, so it wouldn't survive into a real browser request.
 */
function loginAsAndVisitClearTheDeck(User $user, string $path): PendingAwaitablePage
{
    $page = visit('/login');

    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('#login-submit');

    $page->navigate($path);

    return $page;
}

test('the active player sees their hand and play controls', function () {
    [$room, , $users] = startedClearTheDeckRoom();

    $page = loginAsAndVisitClearTheDeck($users[1], "/clear-the-deck?room={$room->code}");

    $page->assertSee('Your turn')
        ->assertSee('Play')
        ->assertDontSee('Waiting for');
});

test('a seat waiting for another player\'s turn sees a waiting message instead of play controls', function () {
    [$room, , $users] = startedClearTheDeckRoom();

    $page = loginAsAndVisitClearTheDeck($users->first(), "/clear-the-deck?room={$room->code}");

    $page->assertSee('Waiting for '.$users[1]->name)
        ->assertDontSee('Your turn');
});

test('an unseated spectator sees a watching message and no play controls', function () {
    [$room] = startedClearTheDeckRoom();
    $spectator = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAsAndVisitClearTheDeck($spectator, "/clear-the-deck?room={$room->code}");

    $page->assertSee('Watching')
        ->assertDontSee('Your turn');
});
