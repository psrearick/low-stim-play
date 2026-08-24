<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\PunchlineMatchupStatus;
use App\Enums\PunchlinePhase;
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
 * docblock for why (two concurrent visit() pages reliably hang in this
 * environment). 4 seats so round 1 is a clean set of plain 2-player
 * matchups (PairingServiceTest's own hand-verified P=4 pairing) -- the
 * one CSS/DOM-driven behavior this game needs covered live is several
 * matchups rendering their own independent status
 * (writing/voting/waiting) at once on one page, which
 * tests/Feature/Games/PunchlineRoomTest.php's Livewire::test() coverage
 * can exercise the underlying state for but can't actually see rendered.
 *
 * @param  array<string, mixed>  $matchupOverrides  merged into matchup r1-1 (seats 1 & 2)
 * @return array{0: Room, 1: GameSession, 2: Collection<int, User>}
 */
function seededPunchlineRoom(array $matchupOverrides = []): array
{
    $host = User::factory()->create(['password' => bcrypt('password')]);
    $guest1 = User::factory()->create(['password' => bcrypt('password')]);
    $guest2 = User::factory()->create(['password' => bcrypt('password')]);
    $guest3 = User::factory()->create(['password' => bcrypt('password')]);
    $users = collect([$host, $guest1, $guest2, $guest3]);

    $room = Room::factory()->active()->create(['host_user_id' => $host->id]);

    $matchups = [
        ['id' => 'r1-0', 'promptId' => 1, 'promptText' => 'Prompt A', 'seatIndices' => [3, 0], 'answersPerSeat' => 1, 'status' => PunchlineMatchupStatus::Writing->value, 'submittedCounts' => [], 'votedSeats' => [], 'answers' => [], 'results' => null],
        [...[
            'id' => 'r1-1', 'promptId' => 2, 'promptText' => 'The worst gift you could give a toddler', 'seatIndices' => [1, 2], 'answersPerSeat' => 1, 'status' => PunchlineMatchupStatus::Writing->value, 'submittedCounts' => [], 'votedSeats' => [], 'answers' => [], 'results' => null,
        ], ...$matchupOverrides],
        ['id' => 'r1-2', 'promptId' => 3, 'promptText' => 'Prompt C', 'seatIndices' => [3, 2], 'answersPerSeat' => 1, 'status' => PunchlineMatchupStatus::Writing->value, 'submittedCounts' => [], 'votedSeats' => [], 'answers' => [], 'results' => null],
        ['id' => 'r1-3', 'promptId' => 4, 'promptText' => 'Prompt D', 'seatIndices' => [0, 1], 'answersPerSeat' => 1, 'status' => PunchlineMatchupStatus::Writing->value, 'submittedCounts' => [], 'votedSeats' => [], 'answers' => [], 'results' => null],
    ];

    $state = [
        'round' => 1,
        'phase' => PunchlinePhase::Writing->value,
        'pool' => 1000,
        'seats' => [
            ['seatIndex' => 0, 'name' => $host->name],
            ['seatIndex' => 1, 'name' => $guest1->name],
            ['seatIndex' => 2, 'name' => $guest2->name],
            ['seatIndex' => 3, 'name' => $guest3->name],
        ],
        'scores' => [0, 0, 0, 0],
        'pointsByRound' => [],
        'sweepsWon' => [],
        'writingEndsAt' => now()->addSeconds(75)->timestamp,
        'votingEndsAt' => null,
        'usedPromptIds' => [1, 2, 3, 4],
        'matchups' => $matchups,
        'message' => null,
    ];

    $session = GameSession::factory()->create([
        'type' => GameType::Punchline,
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
 * A real login through the actual form -- not actingAs(), which only sets
 * the container's resolved guard user and never touches a real session
 * cookie, so it wouldn't survive into a real browser request.
 */
function loginAsAndVisitPunchline(User $user, string $path): PendingAwaitablePage
{
    $page = visit('/login');

    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('#login-submit');

    $page->navigate($path);

    return $page;
}

test('an author sees their own prompt, submits an answer, and the matchup shows waiting until the other author finishes', function () {
    [$room, , $users] = seededPunchlineRoom();
    $guest1 = $users[1]; // seat 1, matchup r1-1

    $page = loginAsAndVisitPunchline($guest1, "/punchline?room={$room->code}");

    $page->assertSee('The worst gift you could give a toddler')
        ->fill('#answer-r1-1-0', 'A live tarantula')
        ->click('#submit-r1-1-0');

    $page->assertSee('Waiting for the other player');
});

test('several matchups render their own independent writing status at once on the same page', function () {
    [$room] = seededPunchlineRoom();
    $spectator = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAsAndVisitPunchline($spectator, "/punchline?room={$room->code}");

    $page->assertSee('Prompt A')
        ->assertSee('The worst gift you could give a toddler')
        ->assertSee('Prompt C')
        ->assertSee('Prompt D');
});

test('an eligible voter sees anonymized answers and can cast a vote', function () {
    [$room, , $users] = seededPunchlineRoom([
        'status' => PunchlineMatchupStatus::Voting->value,
        'submittedCounts' => [1 => 1, 2 => 1],
        'answers' => [
            ['answerId' => '1-0', 'text' => 'A live tarantula'],
            ['answerId' => '2-0', 'text' => 'A drum set'],
        ],
    ]);
    $host = $users[0]; // seat 0, eligible to vote on r1-1 (seats 1 & 2)

    $page = loginAsAndVisitPunchline($host, "/punchline?room={$room->code}");

    $page->assertSee('Vote for the funnier answer')
        ->assertSee('A live tarantula')
        ->assertSee('A drum set')
        ->click('A drum set');

    $page->assertSee('Vote cast');
});

test('an author of a matchup that has moved on to voting sees the answers read-only, with no vote buttons of their own', function () {
    [$room, , $users] = seededPunchlineRoom([
        'status' => PunchlineMatchupStatus::Voting->value,
        'submittedCounts' => [1 => 1, 2 => 1],
        'answers' => [
            ['answerId' => '1-0', 'text' => 'A live tarantula'],
            ['answerId' => '2-0', 'text' => 'A drum set'],
        ],
    ]);
    $guest1 = $users[1]; // seat 1 -- an author of r1-1, not eligible to vote on it

    $page = loginAsAndVisitPunchline($guest1, "/punchline?room={$room->code}");

    $page->assertSee('Voting in progress')
        ->assertSee('A live tarantula')
        ->assertDontSee('Vote for the funnier answer');
});

test('the round status line shows the round number, pool, and a live seconds-remaining countdown', function () {
    [$room, , $users] = seededPunchlineRoom();

    $page = loginAsAndVisitPunchline($users[0], "/punchline?room={$room->code}");

    $page->assertSee('Round 1 of 3')
        ->assertSee('Pool 1000')
        ->assertSee('left to write');
});

/**
 * Same shape as seededPunchlineRoom() above, but parked at RoundReveal
 * with every round-1 matchup already Closed (docs/concerns.md #6/#3) --
 * the phase the host-only "Next Round"/"End Game" controls actually
 * render in.
 *
 * @return array{0: Room, 1: GameSession, 2: Collection<int, User>}
 */
function seededPunchlineRoomAtReveal(): array
{
    $host = User::factory()->create(['password' => bcrypt('password')]);
    $guest1 = User::factory()->create(['password' => bcrypt('password')]);
    $guest2 = User::factory()->create(['password' => bcrypt('password')]);
    $guest3 = User::factory()->create(['password' => bcrypt('password')]);
    $users = collect([$host, $guest1, $guest2, $guest3]);

    $room = Room::factory()->active()->create(['host_user_id' => $host->id]);

    $closedResults = [
        'authorsByAnswerId' => [],
        'pointsByAnswerId' => [],
        'sweepAnswerId' => null,
        'totalVotes' => 0,
    ];

    $matchups = collect([
        ['id' => 'r1-0', 'seatIndices' => [3, 0]],
        ['id' => 'r1-1', 'seatIndices' => [1, 2]],
        ['id' => 'r1-2', 'seatIndices' => [3, 2]],
        ['id' => 'r1-3', 'seatIndices' => [0, 1]],
    ])->map(fn (array $m) => [
        'id' => $m['id'],
        'promptId' => 1,
        'promptText' => 'Prompt',
        'seatIndices' => $m['seatIndices'],
        'answersPerSeat' => 1,
        'status' => PunchlineMatchupStatus::Closed->value,
        'submittedCounts' => [],
        'votedSeats' => [],
        'answers' => [],
        'results' => $closedResults,
    ])->all();

    $state = [
        'round' => 1,
        'phase' => PunchlinePhase::RoundReveal->value,
        'pool' => 1000,
        'seats' => [
            ['seatIndex' => 0, 'name' => $host->name],
            ['seatIndex' => 1, 'name' => $guest1->name],
            ['seatIndex' => 2, 'name' => $guest2->name],
            ['seatIndex' => 3, 'name' => $guest3->name],
        ],
        'scores' => [0, 0, 0, 0],
        'pointsByRound' => [],
        'sweepsWon' => [],
        'writingEndsAt' => null,
        'votingEndsAt' => null,
        'usedPromptIds' => [1, 2, 3, 4],
        'matchups' => $matchups,
        'message' => null,
    ];

    $session = GameSession::factory()->create([
        'type' => GameType::Punchline,
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

test('only the host sees the Next Round button at round reveal; everyone else sees a waiting message', function () {
    [$room, , $users] = seededPunchlineRoomAtReveal();

    $hostPage = loginAsAndVisitPunchline($users[0], "/punchline?room={$room->code}");
    $hostPage->assertSee('Round 1 complete')
        ->assertSee('Next Round')
        ->assertVisible('#advance-round-btn');
});

test('a non-host sees a waiting message instead of the Next Round button at round reveal', function () {
    [$room, , $users] = seededPunchlineRoomAtReveal();

    $guestPage = loginAsAndVisitPunchline($users[1], "/punchline?room={$room->code}");
    $guestPage->assertSee('Round 1 complete')
        ->assertDontSee('Next Round')
        ->assertSee('Waiting for the host to continue');
});

test('the host can open the Game menu, confirm End Game, and see final scores', function () {
    [$room, , $users] = seededPunchlineRoomAtReveal();

    $page = loginAsAndVisitPunchline($users[0], "/punchline?room={$room->code}");

    $page->click('#game-menu-toggle')
        ->assertSee('End Game')
        ->click('#end-game-trigger')
        ->assertSee('End the game now and show final scores?')
        ->click('#end-game-confirm');

    $page->assertSee('Final Score');
});

test('a non-host does not see the End Game button in the Game menu', function () {
    [$room, , $users] = seededPunchlineRoomAtReveal();

    $page = loginAsAndVisitPunchline($users[1], "/punchline?room={$room->code}");

    $page->click('#game-menu-toggle')
        ->assertDontSee('End Game');
});
