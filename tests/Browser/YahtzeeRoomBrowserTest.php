<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\YahtzeeCategory;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Pest\Browser\Api\PendingAwaitablePage;

uses(RefreshDatabase::class);

/**
 * Real multiplayer sync (two live browsers reacting to a Reverb broadcast)
 * isn't exercised here -- BROADCAST_CONNECTION is null in the test
 * environment (see phpunit.xml), so no broadcast would ever actually
 * arrive at a second real browser during a test regardless, exactly why
 * tests/Feature/Games/YahtzeeRoomTest.php already drives each seat through
 * its own isolated Livewire::test() instance rather than two live sockets.
 * These browser tests instead drive ONE real seat's browser (verifying
 * real DOM/CSS/JS this app has repeatedly gotten wrong) while the "rest of
 * the room" is seeded directly at the database level -- the same
 * GameSession.state/RoomSeat shape pages::yahtzee.room itself writes.
 *
 * Two real concurrent Playwright pages were tried first and reliably hung
 * (killed after 30-60s every time) as soon as a content assertion ran on
 * one page followed by a fresh visit() for a second -- an apparent
 * limitation of pest-plugin-browser v5.0.0's current session handling,
 * not something this app's code can fix. Single-page-per-test sidesteps
 * it entirely.
 *
 * @return array{0: Room, 1: GameSession, 2: Collection<int, User>}
 */
function startedYahtzeeRoom(int $seatCount = 2): array
{
    $users = User::factory()->count($seatCount)->create(['password' => bcrypt('password')]);
    $room = Room::factory()->active()->create(['host_user_id' => $users->first()->id]);

    $emptyScores = array_fill_keys(
        array_map(fn (YahtzeeCategory $category) => $category->value, YahtzeeCategory::all()),
        null,
    );

    $state = [
        'seats' => $users->values()->map(fn (User $user, int $index) => [
            'seatIndex' => $index,
            'name' => $user->name,
            'scores' => $emptyScores,
            'yahtzeeBonusCount' => 0,
        ])->all(),
        'currentSeat' => 0,
        'dice' => [],
        'kept' => [],
        'rollsLeft' => 3,
        'gameOver' => false,
    ];

    $session = GameSession::factory()->create([
        'type' => GameType::Yahtzee,
        'mode' => GameMode::Online,
        'settings' => ['player_count' => $seatCount],
        'state' => $state,
    ]);

    $room->update(['game_session_id' => $session->id]);

    foreach ($users as $index => $user) {
        RoomSeat::factory()->create([
            'room_id' => $room->id,
            'seat_index' => $index,
            'user_id' => $user->id,
        ]);
    }

    return [$room, $session, $users];
}

/**
 * A real login through the actual form -- not actingAs(), which only sets
 * the container's resolved guard user and never touches a real session
 * cookie, so it wouldn't survive into a real browser request the way a
 * genuine login does.
 */
function loginAsAndVisit(User $user, string $path): PendingAwaitablePage
{
    $page = visit('/login');

    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('#login-submit');

    $page->navigate($path);

    return $page;
}

test('a host sees a dropdown of their own rooms on the setup screen and can jump into one', function () {
    $host = User::factory()->create(['password' => bcrypt('password')]);
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $page = loginAsAndVisit($host, '/yacht');

    $page->assertSee('Play in a room you host')
        ->click('#hosted-room-'.$room->code);

    $page->assertPathIs('/yacht/room/'.$room->code)
        ->assertSee('Open Lobby')
        ->click('#open-lobby-btn');

    $page->assertSee("I'm ready");
});

test('the current seat can roll and score a category from a real browser', function () {
    [$room, , $users] = startedYahtzeeRoom();

    $page = loginAsAndVisit($users->first(), "/yacht?room={$room->code}");

    $page->assertSee($users->first()->name)
        ->assertVisible('#rollBtn')
        ->click('#rollBtn')
        ->wait(0.3);

    // docs/concerns.md: tapping a pickable cell opens a confirm dialog
    // instead of scoring immediately -- see YahtzeeLocalPlayTest.php's
    // own identical comment.
    $page->click('.score-cell.pickable >> nth=0')->wait(0.3);
    $page->assertVisible('#score-confirm-dialog');
    $page->click('#score-confirm-btn')->wait(0.3);

    $page->assertPresent('.score-cell.filled');
});

/**
 * Coverage audit gap (see docs/DEVLOG.md's Boggle "New Game" bug root
 * cause for why this app now audits every teleported button-bar action
 * for a real click, not just an assertSee): a seated player's own
 * "Watch only"/"Player view" toggle -- the exact pattern this app then
 * copied into Farkle's and Clear the Deck's own play pages -- had never
 * actually been clicked in a real browser for Yahtzee, only for Clear
 * the Deck. Mirrors that file's own test almost exactly.
 */
test('a seated player can toggle Watch only mid-turn to view read-only, and switch back', function () {
    [$room, , $users] = startedYahtzeeRoom();

    $page = loginAsAndVisit($users->first(), "/yacht?room={$room->code}");

    $page->assertVisible('#rollBtn')
        ->click('#game-menu-toggle')
        ->click('Watch only')
        ->wait(0.3);

    $page->assertMissing('#rollBtn');

    // Watch only's own click closes the Game dialog it lives in (see
    // app.js's own comment on that) -- Player view, teleported into that
    // same dialog, needs it reopened first, and the same afterward for
    // Watch only once Player view closes it again.
    $page->click('#game-menu-toggle')
        ->assertSee('Player view')
        ->click('Player view')
        ->wait(0.3);

    $page->assertVisible('#rollBtn');

    $page->click('#game-menu-toggle')
        ->assertSee('Watch only');
});

/**
 * Coverage audit gap: an online game's "Back to room" button (shown once
 * $gameOver is true) had never actually been clicked in a real browser --
 * mirrors tests/Browser/FarkleBackToRoomTest.php's own real click +
 * assertPathIs pattern, the one other game that already had this.
 */
test('Back to room actually navigates to the room hub once the game is over', function () {
    [$room, $session, $users] = startedYahtzeeRoom();

    $session->update(['state' => [...$session->state, 'gameOver' => true]]);

    $page = loginAsAndVisit($users->first(), "/yacht?room={$room->code}");

    $page->click('#game-menu-toggle')
        ->assertSee('Back to room')
        ->click('Back to room')
        ->assertPathIs('/room/'.$room->code);
});

test('an unseated visitor gets the read-only spectator view, not turn controls', function () {
    [$room] = startedYahtzeeRoom();
    $spectator = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAsAndVisit($spectator, "/yacht/room/{$room->code}");

    $page->assertPathIs('/yacht')
        ->assertMissing('#rollBtn')
        ->assertVisible('.dice-area')
        ->assertSourceMissing('not-mine');
});

test('every seat\'s column is visible in the main scorecard on a narrow phone, no separate drawer (docs/concerns.md)', function () {
    // The mobile-only single-seat narrowing + separate "Scores" drawer
    // this used to have (see docs/DEVLOG.md's Yahtzee section) was
    // removed -- the main view always shows every column now, on every
    // viewport, relying on horizontal scroll instead.
    [$room, , $users] = startedYahtzeeRoom(4);

    $page = loginAsAndVisit($users->first(), "/yacht?room={$room->code}");
    $page->resize(390, 844);

    $page->assertMissing('#scores-btn')
        ->assertMissing('#scoreboard-drawer');

    foreach ($users as $user) {
        $page->assertSee($user->name);
    }
});

test('the scorecard actually scrolls horizontally on a narrow phone when there are too many players to fit', function () {
    [$room, , $users] = startedYahtzeeRoom(6);

    $page = loginAsAndVisit($users->first(), "/yacht?room={$room->code}");
    $page->resize(390, 844);

    $page->assertSee($users->first()->name);

    $before = $page->script(
        "(() => { const el = document.querySelector('.card-wrap'); return { scrollWidth: el.scrollWidth, clientWidth: el.clientWidth, scrollLeft: el.scrollLeft }; })()"
    );

    expect($before['scrollWidth'])->toBeGreaterThan($before['clientWidth']);
    expect($before['scrollLeft'])->toBe(0);

    $page->script("document.querySelector('.card-wrap').scrollLeft = 9999");

    $after = $page->script("document.querySelector('.card-wrap').scrollLeft");

    expect($after)->toBeGreaterThan(0);
});

/**
 * docs/concerns.md's "advancing from one game to the next" item
 * generalizes Clear the Deck's own no-scrolling-while-spectating fix to
 * every game with a central spectator view -- unlike the narrow-phone case
 * just above (where horizontal scroll is the deliberate, tested design),
 * --scorecard-player-width/-category-width used a flat ch-based floor at
 * tablet-and-up too, so a real room with enough players still forced the
 * same horizontal scrollbar on a TV/tablet-width viewport that has no way
 * to scroll. Confirmed live at 8 players/1024px before fixing. Only the
 * >=900px column-width override changed (app.css) -- this stays scoped to
 * that same breakpoint, leaving the test just above untouched.
 */
test('the scorecard fits an 8-player game on a tablet-width viewport with no horizontal scrolling', function () {
    [$room, , $users] = startedYahtzeeRoom(8);

    $page = loginAsAndVisit($users->first(), "/yacht?room={$room->code}");
    $page->resize(1024, 768);

    $page->assertSee($users->first()->name);

    $info = $page->script(
        "(() => { const el = document.querySelector('.card-wrap'); return { scrollWidth: el.scrollWidth, clientWidth: el.clientWidth }; })()"
    );

    expect($info['scrollWidth'])->toBeLessThanOrEqual($info['clientWidth']);
});

/**
 * docs/TODO.md's former #14: a very short, wide viewport (1920x750,
 * simulating an aspect-ratio change like AirPlay/screen mirroring) still
 * needed vertical scrolling to see all 17 scorecard rows even at the
 * cqh-based row height's 10px font floor. Root cause turned out not to be
 * the floor at all: .yahtzee-play .row (the 5-die grid) had flex: 1 1
 * auto, and a positive flex-grow inflated it -- and, since dice are
 * aspect-ratio: 1/1, .dice-area's whole height along with it -- to nearly
 * fill .dice-area's full-width flex row, regardless of the intended
 * min(56vw, 42vh, 720px) cap. Confirmed live even on an ordinary
 * 1920x1080 desktop viewport (.row rendered 1860px wide against a ~454px
 * intended cap) before fixing app.css to flex: 0 1 auto -- this test
 * guards the short-viewport symptom that originally surfaced it.
 */
test('the scorecard fits an 8-player game on a short, wide TV-cast viewport with no vertical scrolling', function () {
    [$room, , $users] = startedYahtzeeRoom(8);

    $page = loginAsAndVisit($users->first(), "/yacht?room={$room->code}");
    $page->resize(1920, 750);

    $page->assertSee($users->first()->name);

    $info = $page->script(
        "(() => { const el = document.querySelector('.card-wrap'); return { scrollHeight: el.scrollHeight, clientHeight: el.clientHeight }; })()"
    );

    expect($info['scrollHeight'])->toBeLessThanOrEqual($info['clientHeight']);
});
