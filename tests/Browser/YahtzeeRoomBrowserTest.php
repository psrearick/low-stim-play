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
        ->click('button[type=submit]');

    $page->navigate($path);

    return $page;
}

test('a real account can host an online room from the setup screen', function () {
    $host = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAsAndVisit($host, '/yahtzee');

    $page->assertSee('Host an Online Room')
        ->click('Host an Online Room');

    $page->assertPathBeginsWith('/yahtzee/room/')
        ->assertSee("I'm ready");
});

test('a guest can join an existing room by code from the setup screen', function () {
    $room = Room::factory()->create();

    $page = visit('/yahtzee');

    $page->fill('#join', $room->code)
        ->click('Join');

    $page->assertPathBeginsWith('/yahtzee/room/');
});

test('the current seat can roll and score a category from a real browser', function () {
    [$room, , $users] = startedYahtzeeRoom();

    $page = loginAsAndVisit($users->first(), "/yahtzee?room={$room->code}");

    $page->assertSee($users->first()->name)
        ->assertVisible('#rollBtn')
        ->click('#rollBtn')
        ->wait(0.3);

    $page->click('.score-cell.pickable >> nth=0')->wait(0.3);

    $page->assertPresent('.score-cell.filled');
});

test('an unseated visitor gets the read-only spectator view, not turn controls', function () {
    [$room] = startedYahtzeeRoom();
    $spectator = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAsAndVisit($spectator, "/yahtzee/room/{$room->code}");

    $page->assertPathIs('/yahtzee')
        ->assertMissing('#rollBtn')
        ->assertVisible('.dice-area')
        ->assertSourceMissing('not-mine');
});

test('the mobile scores drawer opens for a seated player and stays open across a state update', function () {
    [$room, $session, $users] = startedYahtzeeRoom();

    $page = loginAsAndVisit($users->first(), "/yahtzee?room={$room->code}");
    $page->resize(390, 844);

    $page->assertVisible('#scores-btn')
        ->click('#scores-btn')
        ->assertVisible('#scoreboard-drawer');

    // Simulates a broadcast from the other seat arriving while the drawer
    // is open -- any Livewire re-render used to silently close this
    // dialog (its `open` attribute isn't server-rendered state, so
    // morphing reset it) until wire:ignore.self was added. See
    // docs/DEVLOG.md's Yahtzee section. Calling onStateUpdated() directly
    // via window.Livewire stands in for the real broadcast, which
    // BROADCAST_CONNECTION=null suppresses entirely in the test env.
    $session->refresh();
    $state = $session->state;
    $state['seats'][1]['scores']['chance'] = 20;
    $json = json_encode($state);

    $page->script(<<<JS
        (() => {
            const el = document.querySelector("[wire\\\\:id]");
            const component = window.Livewire.find(el.getAttribute("wire:id"));
            component.call("onStateUpdated", {state: {$json}});
            return true;
        })()
        JS);

    $page->assertVisible('#scoreboard-drawer');
});

test('the mobile scores drawer shows the current roll status and dice, not just the scorecard', function () {
    [$room, , $users] = startedYahtzeeRoom();

    $page = loginAsAndVisit($users->first(), "/yahtzee?room={$room->code}");
    $page->resize(390, 844);

    $page->click('#rollBtn')->wait(0.3);

    $page->click('#scores-btn')
        ->assertVisible('#scoreboard-drawer')
        ->assertSee('Roll 1 of 3')
        ->assertVisible('#scoreboard-drawer .dice-area');
});
