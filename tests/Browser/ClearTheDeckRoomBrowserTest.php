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

    $page->assertSee('Your hand')
        ->assertSee('Submit')
        ->assertDontSee('Waiting for');
});

test('a seat waiting for another player\'s turn sees a waiting message instead of play controls', function () {
    [$room, , $users] = startedClearTheDeckRoom();

    $page = loginAsAndVisitClearTheDeck($users->first(), "/clear-the-deck?room={$room->code}");

    $page->assertSee('Waiting for '.$users[1]->name)
        ->assertDontSee('Your hand');
});

test('an unseated spectator sees a watching message and no play controls', function () {
    [$room] = startedClearTheDeckRoom();
    $spectator = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAsAndVisitClearTheDeck($spectator, "/clear-the-deck?room={$room->code}");

    $page->assertSee('Watching')
        ->assertDontSee('Your hand');
});

test('a spectator sees every seat\'s tableau at once, with the current turn outlined (docs/concerns.md)', function () {
    [$room, , $users] = startedClearTheDeckRoom();
    $spectator = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAsAndVisitClearTheDeck($spectator, "/clear-the-deck?room={$room->code}");

    // Every seat, not just "opponents" -- a real spectator has no seat of
    // their own to exclude anyone for.
    foreach ($users as $user) {
        $page->assertSee($user->name);
    }

    $page->assertMissing('#play-cards')
        ->assertMissing('.ctd-hand-grid');

    // seat 1 (guest1) holds the turn in startedClearTheDeckRoom() -- only
    // that seat's wrapper should carry the active-turn outline, and its
    // name should carry the "▸" marker (docs/TODO.md's former #16 -- a
    // shape-based signal, not just --ctd-active's color, so whose turn it
    // is still reads correctly on a poorly color-calibrated TV or for a
    // colorblind viewer).
    $info = $page->script(
        "(() => { const seats = [...document.querySelectorAll('.ctd-spectator-seat')]; return seats.map(el => ({ active: el.classList.contains('ctd-spectator-seat-active'), name: el.querySelector('.ctd-spectator-name').textContent.trim() })); })()"
    );

    $activeNames = collect($info)->where('active', true)->pluck('name')->all();

    expect($activeNames)->toBe(['▸ '.$users[1]->name]);

    $inactiveNames = collect($info)->where('active', false)->pluck('name')->all();

    foreach ($inactiveNames as $name) {
        expect($name)->not->toStartWith('▸');
    }
});

test('a seated player can toggle Watch only mid-turn to view read-only, and switch back', function () {
    [$room, , $users] = startedClearTheDeckRoom();

    // seat 1 (guest1) holds the turn -- log in as them so this exercises
    // toggling away from an active hand/Submit view, not just an already
    // read-only waiting one.
    $page = loginAsAndVisitClearTheDeck($users[1], "/clear-the-deck?room={$room->code}");

    $page->assertVisible('#play-cards')
        ->assertSee('Your hand')
        ->click('#game-menu-toggle')
        ->click('Watch only')
        ->wait(0.3);

    $page->assertMissing('#play-cards')
        ->assertDontSee('Your hand')
        ->assertSee('Watching');

    $page->click('Player view')->wait(0.3);

    $page->assertVisible('#play-cards')
        ->assertSee('Your hand');
});

/**
 * docs/concerns.md's Clear the Deck spectator item: at the game's own
 * 10-player max (docs/specs/clear-the-deck.md), every seat's tableau used
 * to only wrap 2-per-row (card size was picked from viewport width alone,
 * with no idea how many seats needed to share it), pushing the page to
 * several screens tall. $spectatorCols/$spectatorSize in the Blade now
 * size cards from both viewport width and height, capped at 5 columns.
 */
test('the 10-player spectator grid fits a tablet-width viewport with no scrolling (docs/concerns.md)', function () {
    $host = User::factory()->create();
    $users = collect(range(0, 9))->map(fn ($i) => User::factory()->create(['name' => 'Player '.($i + 1)]));

    $room = Room::factory()->active()->create(['host_user_id' => $host->id]);

    $seats = [];
    $seatTables = [];
    $scores = [];
    $roundsWonCounts = [];

    foreach ($users as $i => $user) {
        $seats[] = ['seatIndex' => $i, 'name' => $user->name];
        $seatTables[$i] = ['handCount' => 8, 'tableFaceUp' => [3, 7], 'tableFaceDownCount' => 4];
        $scores[$i] = 0;
        $roundsWonCounts[$i] = 0;

        RoomSeat::factory()->create([
            'room_id' => $room->id,
            'seat_index' => $i,
            'user_id' => $user->id,
            'hand' => range(1, 8),
            'table_face_up' => [3, 7],
            'table_face_down' => [1, 2, 4, 5],
        ]);
    }

    $session = GameSession::factory()->create([
        'type' => GameType::ClearTheDeck,
        'mode' => GameMode::Online,
        'settings' => ['round_target' => 1],
        'state' => [
            'roundTarget' => 1, 'currentRound' => 1, 'seats' => $seats, 'scores' => $scores,
            'roundsWonCounts' => $roundsWonCounts, 'dealerSeatIndex' => 0, 'turnSeatIndex' => 1,
            'centerStack' => [4, 4], 'phase' => ClearTheDeckPhase::Playing->value, 'lastMessage' => null,
            'roundScores' => [], 'wentOutSeatIndex' => null, 'winnerName' => null, 'seatTables' => $seatTables,
        ],
    ]);

    $room->update(['game_session_id' => $session->id]);

    $spectator = User::factory()->create(['password' => bcrypt('password')]);
    $page = loginAsAndVisitClearTheDeck($spectator, "/clear-the-deck?room={$room->code}");

    $page->assertSee('Player 10');

    // A realistic tightest-case tablet width, not a spacious desktop one --
    // the case that actually used to require scrolling.
    $page->resize(1024, 768)->wait(0.2);

    $overflow = $page->script(
        '({ x: document.documentElement.scrollWidth - window.innerWidth, y: document.documentElement.scrollHeight - window.innerHeight })'
    );

    expect($overflow['x'])->toBeLessThanOrEqual(0)
        ->and($overflow['y'])->toBeLessThanOrEqual(0);
});

/**
 * docs/concerns.md: "table cards should be at the top, not the bottom" --
 * the pile row now renders before the seat grid specifically in spectator
 * view (the playing view's own order, opponents row then pile, is
 * unchanged, since a seated player already has their own hand/columns
 * below the pile to read in sequence).
 */
test('the pile renders above the seat grid while spectating (docs/concerns.md)', function () {
    [$room, , $users] = startedClearTheDeckRoom();
    $spectator = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAsAndVisitClearTheDeck($spectator, "/clear-the-deck?room={$room->code}");

    $order = $page->script(
        "(() => { const pile = document.querySelector('.ctd-pile-row'); const grid = document.querySelector('.ctd-spectator-grid'); return pile.compareDocumentPosition(grid) & Node.DOCUMENT_POSITION_FOLLOWING ? 'pile-first' : 'grid-first'; })()"
    );

    expect($order)->toBe('pile-first');
});

/**
 * Coverage audit gap (see docs/DEVLOG.md's Boggle "New Game" bug root
 * cause): tests/Browser/ButtonBarOverflowTest.php already covers this
 * button's on-screen *position* at game end, but never actually clicks
 * it -- mirrors tests/Browser/FarkleBackToRoomTest.php's own real click +
 * assertPathIs pattern.
 */
test('Back to room actually navigates to the room hub once the game is over', function () {
    [$room, $session, $users] = startedClearTheDeckRoom();

    $session->update(['state' => [...$session->state, 'phase' => ClearTheDeckPhase::GameEnd->value]]);

    $page = loginAsAndVisitClearTheDeck($users->first(), "/clear-the-deck?room={$room->code}");

    $page->click('#game-menu-toggle')
        ->assertSee('Back to room')
        ->click('Back to room')
        ->assertPathIs('/room/'.$room->code);
});
