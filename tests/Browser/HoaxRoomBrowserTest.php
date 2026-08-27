<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\HoaxPhase;
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
 * tests/Browser/PunchlineRoomBrowserTest.php -- see that file's own
 * docblock for why (two concurrent visit() pages reliably hang in this
 * environment). 3 seats, parked mid-voting with one already-written
 * round -- the one CSS/DOM-driven behavior this game needs covered live
 * is the interpolated-sentence voting list (a plain Livewire::test()
 * can inspect the underlying state but can't see the rendered HTML) and
 * the mobile answer-input font-size.
 *
 * @return array{0: Room, 1: GameSession, 2: Collection<int, User>}
 */
function seededHoaxRoom(): array
{
    $host = User::factory()->create(['password' => bcrypt('password')]);
    $guest1 = User::factory()->create(['password' => bcrypt('password')]);
    $guest2 = User::factory()->create(['password' => bcrypt('password')]);
    $users = collect([$host, $guest1, $guest2]);

    $room = Room::factory()->active()->create(['host_user_id' => $host->id]);

    $state = [
        'round' => 1,
        'phase' => HoaxPhase::Writing->value,
        'questionId' => 1,
        'questionText' => 'The world\'s largest ____ is located in Nebraska.',
        'seats' => [
            ['seatIndex' => 0, 'name' => $host->name],
            ['seatIndex' => 1, 'name' => $guest1->name],
            ['seatIndex' => 2, 'name' => $guest2->name],
        ],
        'scores' => [0, 0, 0],
        'pointsByRound' => [],
        'truthsFound' => [],
        'writingEndsAt' => now()->addSeconds(75)->timestamp,
        'votingEndsAt' => null,
        'usedQuestionIds' => [1],
        'submittedSeats' => [],
        'votedSeats' => [],
        'answers' => [],
        'results' => null,
        'message' => null,
    ];

    $session = GameSession::factory()->create([
        'type' => GameType::Hoax,
        'mode' => GameMode::Online,
        'settings' => [],
        'state' => $state,
    ]);

    $room->update(['game_session_id' => $session->id]);

    foreach ($users as $i => $user) {
        RoomSeat::factory()->create(['room_id' => $room->id, 'seat_index' => $i, 'user_id' => $user->id]);
    }

    return [$room, $session, $users];
}

/**
 * Same shape as seededHoaxRoom() above, but parked at Voting with every
 * seat having already written -- the phase the interpolated-sentence
 * voting list and own-answer-excluded behavior actually render in.
 *
 * @return array{0: Room, 1: GameSession, 2: Collection<int, User>}
 */
function seededHoaxRoomAtVoting(): array
{
    [$room, $session, $users] = seededHoaxRoom();

    $state = $session->state;
    $state['phase'] = HoaxPhase::Voting->value;
    $state['writingEndsAt'] = null;
    $state['votingEndsAt'] = now()->addSeconds(60)->timestamp;
    $state['submittedSeats'] = [0, 1, 2];
    $state['answers'] = [
        ['answerId' => '0-0', 'text' => 'wheat'],
        ['answerId' => '1-0', 'text' => 'ball of twine'],
        ['answerId' => 'truth', 'text' => 'ball of stamps'],
    ];
    $session->update(['state' => $state]);

    return [$room, $session, $users];
}

/**
 * Same shape as seededHoaxRoom() above, but parked at RoundReveal with
 * the round fully scored -- the phase the host-only "Next Round"/
 * "End Game" controls actually render in.
 *
 * @return array{0: Room, 1: GameSession, 2: Collection<int, User>}
 */
function seededHoaxRoomAtReveal(): array
{
    [$room, $session, $users] = seededHoaxRoomAtVoting();

    $state = $session->state;
    $state['phase'] = HoaxPhase::RoundReveal->value;
    $state['votingEndsAt'] = null;
    $state['votedSeats'] = [0, 1, 2];
    $state['scores'] = [500, 0, 1000];
    $state['results'] = [
        'pointsBySeat' => [0 => 500, 2 => 1000],
        'truthAnswerId' => 'truth',
        'authorsByAnswerId' => ['0-0' => 0, '1-0' => 1, 'truth' => -1],
        'votesByAnswerId' => ['0-0' => 1, 'truth' => 1],
        'seatsWhoFoundTruth' => [2],
    ];
    $session->update(['state' => $state]);

    return [$room, $session, $users];
}

/**
 * A real login through the actual form -- not actingAs(), which only sets
 * the container's resolved guard user and never touches a real session
 * cookie, so it wouldn't survive into a real browser request.
 */
function loginAsAndVisitHoax(User $user, string $path): PendingAwaitablePage
{
    $page = visit('/login');

    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('#login-submit');

    $page->navigate($path);

    return $page;
}

test('an author sees the question, submits an answer, and the status shows waiting to write', function () {
    [$room, , $users] = seededHoaxRoom();
    $host = $users[0];

    $page = loginAsAndVisitHoax($host, "/hoax?room={$room->code}");

    $page->assertSee('The world\'s largest ____ is located in Nebraska.')
        ->fill('#hoax-answer-input', 'ball of stamps')
        ->click('#hoax-submit-btn');

    $page->assertSee('Waiting for the other players to finish writing');
});

test('the voting list renders each answer as a full interpolated sentence, with the voter\'s own answer excluded', function () {
    [$room, , $users] = seededHoaxRoomAtVoting();
    $host = $users[0]; // seat 0, authored "wheat"

    $page = loginAsAndVisitHoax($host, "/hoax?room={$room->code}");

    $page->assertSee('Which answer is the real one?')
        ->assertDontSee('The world\'s largest wheat is located in Nebraska.') // own answer excluded
        ->assertSee('The world\'s largest ball of twine is located in Nebraska.')
        ->assertSee('The world\'s largest ball of stamps is located in Nebraska.'); // the real one
});

test('casting a vote records it and shows the vote-cast waiting message', function () {
    [$room, , $users] = seededHoaxRoomAtVoting();
    $host = $users[0];

    $page = loginAsAndVisitHoax($host, "/hoax?room={$room->code}");

    // interpolatedText() uppercases the answer portion on display (see
    // that method's own docblock for why) -- unlike assertSee(), which
    // this Pest Browser version matches case-insensitively, click($text)
    // needs the real rendered casing or it waits indefinitely for an
    // element that will never appear.
    $page->click('The world\'s largest BALL OF TWINE is located in Nebraska.');

    $page->assertSee('Vote cast');
});

/**
 * docs/DEVLOG.md's Hoax section: applied the >=16px font-size fix
 * Punchline needed after the fact (see app.css's own
 * #punchline-matchups rule) from day one here instead. Uses
 * on()->iPhoneSE() rather than resize(), per this repo's own documented
 * gotcha that resize() doesn't reliably reflect real narrow-viewport
 * vmin-based clamp() values -- and this is a script()-only check with no
 * click()/fill() afterward, so device emulation's broken click delivery
 * doesn't apply here.
 */
test('the answer input keeps a real 16px+ font-size on a narrow mobile viewport, so iOS Safari does not zoom in on focus', function () {
    [$room, , $users] = seededHoaxRoom();
    $host = $users[0];

    $page = loginAsAndVisitHoax($host, "/hoax?room={$room->code}");
    $page->on()->iPhoneSE();

    $page->assertSee('The world\'s largest ____ is located in Nebraska.');

    $fontSize = $page->script(
        "getComputedStyle(document.querySelector('#hoax-answer-input')).fontSize"
    );

    expect((int) $fontSize)->toBeGreaterThanOrEqual(16);
});

test('only the host sees the Next Round button at round reveal; everyone else sees a waiting message', function () {
    [$room, , $users] = seededHoaxRoomAtReveal();

    $hostPage = loginAsAndVisitHoax($users[0], "/hoax?room={$room->code}");
    $hostPage->assertSee('Round 1 complete')
        ->assertSee('Next Round')
        ->assertVisible('#advance-round-btn');
});

test('a non-host sees a waiting message instead of the Next Round button at round reveal', function () {
    [$room, , $users] = seededHoaxRoomAtReveal();

    $guestPage = loginAsAndVisitHoax($users[1], "/hoax?room={$room->code}");
    $guestPage->assertSee('Round 1 complete')
        ->assertDontSee('Next Round')
        ->assertSee('Waiting for the host to continue');
});

test('the host can open the Game menu, confirm End Game, and see final scores', function () {
    [$room, , $users] = seededHoaxRoomAtReveal();

    $page = loginAsAndVisitHoax($users[0], "/hoax?room={$room->code}");

    $page->click('#game-menu-toggle')
        ->assertSee('End Game')
        ->click('#end-game-trigger')
        ->assertSee('End the game now and show final scores?')
        ->click('#end-game-confirm');

    $page->assertSee('Final Score');
});

test('a non-host does not see the End Game button in the Game menu', function () {
    [$room, , $users] = seededHoaxRoomAtReveal();

    $page = loginAsAndVisitHoax($users[1], "/hoax?room={$room->code}");

    $page->click('#game-menu-toggle')
        ->assertDontSee('End Game');
});
