<?php

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
        ->click('button[type=submit]');

    $page->navigate($path);

    return $page;
}

test('a real account can host an online room from the setup screen', function () {
    $host = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAsAndVisitFeud($host, '/family-feud');

    $page->assertSee('Host an Online Room')
        ->click('Host an Online Room');

    $page->assertPathBeginsWith('/family-feud/room/')
        ->assertSee("I'm ready");
});

test('a guest can join an existing room by code from the setup screen', function () {
    $room = Room::factory()->create();

    $page = visit('/family-feud');

    $page->fill('#join', $room->code)
        ->click('Join');

    $page->assertPathBeginsWith('/family-feud/room/');
});

test('the acting seat sees the guess form, not a waiting message', function () {
    [$room, , $users] = startedFeudRoom();

    // Team 0 (host, seat 0) controls round 1.
    $page = loginAsAndVisitFeud($users->first(), "/family-feud?room={$room->code}");

    $page->assertVisible('.guess-form')
        ->assertDontSee('Waiting for');
});

test('a non-acting seat sees a waiting message instead of the guess form', function () {
    [$room, , $users] = startedFeudRoom();

    // Team 0 (host, seat 0) controls round 1 -- seat 1 (guest) must wait.
    $page = loginAsAndVisitFeud($users->last(), "/family-feud?room={$room->code}");

    $page->assertMissing('.guess-form')
        ->assertSee('Waiting for '.$users->first()->name);
});

test('an unseated spectator sees no guess form and no Reveal/End Game action buttons', function () {
    [$room] = startedFeudRoom();
    $spectator = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAsAndVisitFeud($spectator, "/family-feud?room={$room->code}");

    $page->assertMissing('.guess-form')
        ->assertDontSee('Reveal')
        ->assertDontSee('End Game');
});
