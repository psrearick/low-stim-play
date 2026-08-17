<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\FamilyFeudQuestion;
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
 * tests/Browser/YahtzeeRoomBrowserTest.php -- see that file's own docblock
 * for why (two concurrent visit() pages reliably hang in this environment,
 * and BROADCAST_CONNECTION=null means a second live browser would never
 * actually receive a broadcast during a test anyway). One team per seat
 * (no rotation) keeps these tests focused purely on the visibility bug
 * class under test (teleported buttons / conditional show-hide), which
 * tests/Feature/Games/FamilyFeudRoomTest.php already covers the turn/
 * captain authorization logic for in depth.
 *
 * @return array{0: Room, 1: GameSession, 2: Collection<int, User>}
 */
function startedFeudRoom(): array
{
    $host = User::factory()->create(['password' => bcrypt('password')]);
    $guest = User::factory()->create(['password' => bcrypt('password')]);
    $users = collect([$host, $guest]);

    $room = Room::factory()->active()->create(['host_user_id' => $host->id]);

    $state = [
        'teams' => [
            ['name' => 'Team 1', 'score' => 0],
            ['name' => 'Team 2', 'score' => 0],
        ],
        'teamSeats' => [
            [['seatIndex' => 0, 'name' => $host->name, 'isCaptain' => true]],
            [['seatIndex' => 1, 'name' => $guest->name, 'isCaptain' => true]],
        ],
        'teamTurnPointer' => [0, 0],
        'questions' => [
            ['id' => 1, 'prompt' => 'Name something round.', 'answerCount' => 4],
        ],
        'currentQuestionIndex' => 0,
        'revealed' => [],
        'strikes' => 0,
        'pot' => 0,
        'controllingTeam' => 0,
        'phase' => 'play',
        'roundHistory' => [[0, 0]],
        'roundEndTitle' => '',
        'message' => null,
        'messageIsError' => false,
    ];

    $session = GameSession::factory()->create([
        'type' => GameType::FamilyFeud,
        'mode' => GameMode::Online,
        'settings' => ['rounds' => 1],
        'state' => $state,
    ]);

    $room->update(['game_session_id' => $session->id]);

    RoomSeat::factory()->create(['room_id' => $room->id, 'seat_index' => 0, 'user_id' => $host->id, 'team_index' => 0, 'is_captain' => true]);
    RoomSeat::factory()->create(['room_id' => $room->id, 'seat_index' => 1, 'user_id' => $guest->id, 'team_index' => 1, 'is_captain' => true]);

    return [$room, $session, $users];
}

/**
 * A real login through the actual form -- not actingAs(), which only sets
 * the container's resolved guard user and never touches a real session
 * cookie, so it wouldn't survive into a real browser request.
 */
function loginAsAndVisitFeud(User $user, string $path): PendingAwaitablePage
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

    $page = loginAsAndVisitFeud($host, '/survey-says');

    $page->assertSee('Play in a room you host')
        ->click('#hosted-room-'.$room->code);

    $page->assertPathIs('/survey-says/room/'.$room->code)
        ->assertSee("I'm ready");
});

test('the acting seat sees the guess form, not a waiting message', function () {
    [$room, , $users] = startedFeudRoom();

    // Team 0 (host, seat 0) controls round 1.
    $page = loginAsAndVisitFeud($users->first(), "/survey-says?room={$room->code}");

    $page->assertVisible('.guess-form')
        ->assertDontSee('Waiting for');
});

test('a non-acting seat sees a waiting message instead of the guess form', function () {
    [$room, , $users] = startedFeudRoom();

    // Team 0 (host, seat 0) controls round 1 -- seat 1 (guest) must wait.
    $page = loginAsAndVisitFeud($users->last(), "/survey-says?room={$room->code}");

    $page->assertMissing('.guess-form')
        ->assertSee('Waiting for '.$users->first()->name);
});

test('an unseated spectator sees no guess form and no Reveal/End Game action buttons', function () {
    [$room] = startedFeudRoom();
    $spectator = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAsAndVisitFeud($spectator, "/survey-says?room={$room->code}");

    $page->assertMissing('.guess-form')
        ->assertDontSee('Reveal')
        ->assertDontSee('End Game');
});

/**
 * docs/TODO.md's former #15: unlike Clear the Deck/Farkle/Yahtzee's own
 * spectator views, Family Feud's in-game scoreboard (⚡play.blade.php)
 * only ever renders two fixed team-name/score cards, never a per-player
 * list -- so team roster size (up to Room::factory()'s own max_players
 * default, 8, split 4v4 here) has no way to make it grow at all, and the
 * page has no `.in-game { height: 100dvh }` clip the way those three do.
 * Verified live rather than assumed: still true with a full 4v4 room on a
 * short, wide TV-cast-style viewport.
 */
test('the scoreboard stays a fixed size with no scrolling needed on a short TV-cast viewport at a full 4v4 room', function () {
    $users = User::factory()->count(8)->create(['password' => bcrypt('password')]);
    $room = Room::factory()->active()->create(['host_user_id' => $users->first()->id]);

    $state = [
        'teams' => [
            ['name' => 'Team 1', 'score' => 12],
            ['name' => 'Team 2', 'score' => 8],
        ],
        'teamSeats' => [
            $users->slice(0, 4)->values()->map(fn (User $u, int $i) => ['seatIndex' => $i, 'name' => $u->name, 'isCaptain' => $i === 0])->all(),
            $users->slice(4, 4)->values()->map(fn (User $u, int $i) => ['seatIndex' => $i + 4, 'name' => $u->name, 'isCaptain' => $i === 0])->all(),
        ],
        'teamTurnPointer' => [0, 0],
        'questions' => [
            ['id' => 1, 'prompt' => 'Name something round.', 'answerCount' => 4],
        ],
        'currentQuestionIndex' => 0,
        'revealed' => [],
        'strikes' => 0,
        'pot' => 0,
        'controllingTeam' => 0,
        'phase' => 'play',
        'roundHistory' => [[12, 8]],
        'roundEndTitle' => '',
        'message' => null,
        'messageIsError' => false,
    ];

    $session = GameSession::factory()->create([
        'type' => GameType::FamilyFeud,
        'mode' => GameMode::Online,
        'settings' => ['rounds' => 1],
        'state' => $state,
    ]);

    $room->update(['game_session_id' => $session->id]);

    foreach ($users as $i => $user) {
        RoomSeat::factory()->create([
            'room_id' => $room->id,
            'seat_index' => $i,
            'user_id' => $user->id,
            'team_index' => (int) ($i >= 4),
            'is_captain' => $i === 0 || $i === 4,
        ]);
    }

    $spectator = User::factory()->create(['password' => bcrypt('password')]);
    $page = loginAsAndVisitFeud($spectator, "/survey-says?room={$room->code}");
    $page->resize(1920, 750);

    $page->assertSee('Team 1')->assertSee('Team 2');

    $overflow = $page->script(
        '(() => ({ scrollHeight: document.body.scrollHeight, innerHeight: window.innerHeight }))()'
    );

    expect($overflow['scrollHeight'])->toBeLessThanOrEqual($overflow['innerHeight']);
});

/**
 * Drives the host room page's real onRoomStarting() action directly via
 * window.Livewire, standing in for game-lobby's own 'room-game-starting'
 * dispatch -- that dispatch only ever fires from a real presence-channel
 * "everyone's ready" state, which needs a live broadcast this environment
 * doesn't have (BROADCAST_CONNECTION=null, see phpunit.xml), the same
 * reason startedFeudRoom() above seeds an already-started game directly
 * rather than driving the lobby for real. This only stands in for the
 * *lobby* step -- the team-assignment screen itself, and Start Round,
 * are driven through real clicks below.
 *
 * @param  list<array{id: int, name: string, is_host: bool, ready: bool}>  $players
 */
function triggerFeudRoomStarting(PendingAwaitablePage $page, array $players): void
{
    $json = json_encode($players);

    // Looked up by wire:name, not the first [wire\:id] in the DOM --
    // <livewire:rooms-menu/> now renders earlier in the layout on every
    // page, so a bare "first wire:id element" query silently grabbed it
    // instead of this page's own component and threw a
    // MethodNotFoundException (rooms-menu has no onRoomStarting()) that
    // hung the whole browser session instead of failing fast. Same root
    // cause as tests/Browser/FarkleMobileLayoutTest.php's own fix.
    $page->script(<<<JS
        (() => {
            const el = document.querySelector('[wire\\\\:name="pages::family-feud.room"]');
            const component = window.Livewire.find(el.getAttribute("wire:id"));
            component.call("onRoomStarting", {$json});
            return true;
        })()
        JS);
}

test('the team-assignment screen renders real players and blocks starting once a team is emptied', function () {
    $host = User::factory()->create(['password' => bcrypt('password')]);
    $guest = User::factory()->create(['password' => bcrypt('password')]);
    FamilyFeudQuestion::factory()->count(4)->create();

    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $page = loginAsAndVisitFeud($host, "/survey-says/room/{$room->code}");

    triggerFeudRoomStarting($page, [
        ['id' => $host->id, 'name' => $host->name, 'is_host' => true, 'ready' => true],
        ['id' => $guest->id, 'name' => $guest->name, 'is_host' => false, 'ready' => true],
    ]);

    $page->assertSee($host->name)
        ->assertSee($guest->name)
        ->assertSee('(Captain)')
        ->assertButtonEnabled('Start Round');

    // Host is the only player on Team 1 -- moving them onto Team 2 empties
    // Team 1 for real, and the button should actually disable in the live
    // DOM, not just in server-side logic.
    $page->click('Move to Team 2')->wait(0.3);

    $page->assertSee('Each team needs at least one player.')
        ->assertButtonDisabled('Start Round');
});

test('the host can start the round from the team-assignment screen with a real click', function () {
    $host = User::factory()->create(['password' => bcrypt('password')]);
    $guest = User::factory()->create(['password' => bcrypt('password')]);
    FamilyFeudQuestion::factory()->count(4)->create();

    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $page = loginAsAndVisitFeud($host, "/survey-says/room/{$room->code}");

    triggerFeudRoomStarting($page, [
        ['id' => $host->id, 'name' => $host->name, 'is_host' => true, 'ready' => true],
        ['id' => $guest->id, 'name' => $guest->name, 'is_host' => false, 'ready' => true],
    ]);

    $page->click('Start Round')->wait(0.3);

    // Only the current turn-holder's name is ever shown on screen (see
    // "turnHolderName" in pages::family-feud.play) -- team names are
    // otherwise generic ("Team 1"/"Team 2") -- so assert on markers that
    // are always present in the real game instead of either player's name
    // specifically.
    $page->assertPathIs('/survey-says')
        ->assertDontSee('Start Round')
        ->assertSee('Team 1')
        ->assertSee('Team 2')
        ->assertVisible('.guess-form');
});
