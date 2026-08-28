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
        ['id' => 'r1-0', 'promptId' => 1, 'promptText' => 'Prompt A', 'seatIndices' => [3, 0], 'answersPerSeat' => 1, 'voteLimit' => 1, 'status' => PunchlineMatchupStatus::Writing->value, 'submittedCounts' => [], 'votedSeats' => [], 'answers' => [], 'results' => null],
        [...[
            'id' => 'r1-1', 'promptId' => 2, 'promptText' => 'The worst gift you could give a toddler', 'seatIndices' => [1, 2], 'answersPerSeat' => 1, 'voteLimit' => 1, 'status' => PunchlineMatchupStatus::Writing->value, 'submittedCounts' => [], 'votedSeats' => [], 'answers' => [], 'results' => null,
        ], ...$matchupOverrides],
        ['id' => 'r1-2', 'promptId' => 3, 'promptText' => 'Prompt C', 'seatIndices' => [3, 2], 'answersPerSeat' => 1, 'voteLimit' => 1, 'status' => PunchlineMatchupStatus::Writing->value, 'submittedCounts' => [], 'votedSeats' => [], 'answers' => [], 'results' => null],
        ['id' => 'r1-3', 'promptId' => 4, 'promptText' => 'Prompt D', 'seatIndices' => [0, 1], 'answersPerSeat' => 1, 'voteLimit' => 1, 'status' => PunchlineMatchupStatus::Writing->value, 'submittedCounts' => [], 'votedSeats' => [], 'answers' => [], 'results' => null],
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

/**
 * seat 1 (guest1) authors two matchups in the fixture (r1-1 and r1-3, docs/
 * concerns.md's step-based Writing UI: only this seat's own still-open
 * matchups render, never a matchup it's already filed or isn't part of).
 * Submitting r1-1 leaves r1-3 as the only thing left to write, so it's
 * still the only prompt on screen -- not a "waiting" message yet, since
 * this seat itself still owes an answer elsewhere. Only once both are in
 * does the generic waiting panel show.
 */
test('an author sees only their own still-open prompt, and the waiting message only once all their own writing is done', function () {
    [$room, , $users] = seededPunchlineRoom();
    $guest1 = $users[1]; // seat 1, author of r1-1 (seats 1,2) and r1-3 (seats 0,1)

    $page = loginAsAndVisitPunchline($guest1, "/punchline?room={$room->code}");

    $page->assertSee('The worst gift you could give a toddler')
        ->assertSee('Prompt D')
        ->fill('#answer-r1-1-0', 'A live tarantula')
        ->click('#submit-r1-1-0');

    $page->assertDontSee('The worst gift you could give a toddler')
        ->assertSee('Prompt D')
        ->fill('#answer-r1-3-0', 'A pet snake')
        ->click('#submit-r1-3-0');

    $page->assertDontSee('Prompt D')
        ->assertSee('Waiting for the rest of the table to finish writing');
});

/**
 * docs/concerns.md: the answer input inherited the shared base
 * input[type="text"] rule's font-size, which clamps as low as 13px --
 * well under the 16px floor iOS Safari uses to decide whether to zoom
 * the viewport in on focus. app.css now scopes a 16px+ floor to this
 * input specifically. Uses on()->iPhoneSE() rather than resize(), per
 * this repo's own documented gotcha that resize() doesn't reliably
 * reflect real narrow-viewport vmin-based clamp() values -- and this is
 * a script()-only check with no click()/fill() afterward, so device
 * emulation's broken click delivery doesn't apply here.
 */
test('the answer input keeps a real 16px+ font-size on a narrow mobile viewport, so iOS Safari does not zoom in on focus', function () {
    [$room, , $users] = seededPunchlineRoom();
    $guest1 = $users[1]; // seat 1, author of r1-1's still-open writing matchup

    $page = loginAsAndVisitPunchline($guest1, "/punchline?room={$room->code}");
    $page->on()->iPhoneSE();

    $page->assertSee('The worst gift you could give a toddler');

    $fontSize = $page->script(
        "getComputedStyle(document.querySelector('#answer-r1-1-0')).fontSize"
    );

    expect((int) $fontSize)->toBeGreaterThanOrEqual(16);
});

/**
 * docs/concerns.md's step-based redesign scopes the Writing step to "only
 * the questions you can answer" -- a spectator with no seat is an author of
 * nothing, so unlike the old per-matchup status board, they see none of the
 * 4 round-1 prompts, just the generic waiting message.
 */
test('a spectator with no seat sees none of the round\'s writing prompts, only the waiting message', function () {
    [$room] = seededPunchlineRoom();
    $spectator = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAsAndVisitPunchline($spectator, "/punchline?room={$room->code}");

    $page->assertDontSee('Prompt A')
        ->assertDontSee('The worst gift you could give a toddler')
        ->assertDontSee('Prompt C')
        ->assertDontSee('Prompt D')
        ->assertSee('Waiting for the rest of the table to finish writing');
});

/**
 * The one seat with two still-open writing obligations at once (seat 1
 * authors both r1-1 and r1-3 in the fixture) is the real "several matchups
 * render independent status at once" case under the new step-based UI --
 * scoped to what *this* seat itself owes, not a table-wide board.
 */
test('a seat with two still-open matchups sees both of its own prompts at once', function () {
    [$room, , $users] = seededPunchlineRoom();
    $guest1 = $users[1]; // seat 1, author of r1-1 and r1-3

    $page = loginAsAndVisitPunchline($guest1, "/punchline?room={$room->code}");

    $page->assertSee('The worst gift you could give a toddler')
        ->assertSee('Prompt D')
        ->assertDontSee('Prompt A')
        ->assertDontSee('Prompt C');
});

/**
 * Closes every round-1 matchup except r1-1 (results shape mirrors
 * seededPunchlineRoomAtReveal()'s own $closedResults) so the given seat has
 * no pending writing obligations left elsewhere -- otherwise, under the new
 * step-based UI, a seat with any still-open writing matchup would see that
 * form instead of ever reaching r1-1's own voting step, since writing is
 * always checked first (see getMyPendingWritingMatchupsProperty()'s own
 * docblock).
 */
function closeEveryMatchupExceptR11(GameSession $session): void
{
    $state = $session->state;
    $closedResults = ['authorsByAnswerId' => [], 'pointsByAnswerId' => [], 'sweepAnswerId' => null, 'totalVotes' => 0];

    $state['matchups'] = collect($state['matchups'])->map(fn (array $m) => $m['id'] === 'r1-1' ? $m : [
        ...$m,
        'status' => PunchlineMatchupStatus::Closed->value,
        'results' => $closedResults,
    ])->all();

    $session->update(['state' => $state]);
}

test('an eligible voter sees anonymized answers and can cast a vote', function () {
    [$room, $session, $users] = seededPunchlineRoom([
        'status' => PunchlineMatchupStatus::Voting->value,
        'submittedCounts' => [1 => 1, 2 => 1],
        'answers' => [
            ['answerId' => '1-0', 'text' => 'A live tarantula'],
            ['answerId' => '2-0', 'text' => 'A drum set'],
        ],
    ]);
    closeEveryMatchupExceptR11($session);
    $host = $users[0]; // seat 0, eligible to vote on r1-1 (seats 1 & 2), no other writing owed

    $page = loginAsAndVisitPunchline($host, "/punchline?room={$room->code}");

    $page->assertSee('Vote for the funnier answer')
        ->assertSee('A live tarantula')
        ->assertSee('A drum set')
        ->click('A drum set');

    // Nothing else left for this seat to write or vote on (every other
    // matchup was closed above), so the vote submitting drops straight to
    // the generic waiting panel -- there's no separate per-matchup "Vote
    // cast" confirmation under the new step-based UI.
    $page->assertDontSee('A drum set')
        ->assertSee('Waiting for the rest of the table to finish');
});

/**
 * docs/concerns.md's redesign removes the old "read-only preview of a
 * matchup this seat can't act on" entirely -- an author of a now-Voting
 * matchup they can't vote on sees nothing about it at all (not even its
 * answers), only the generic waiting message, once their own writing
 * elsewhere is also done.
 */
test('an author of a matchup that has moved on to voting sees no trace of it once their own writing is done', function () {
    [$room, $session, $users] = seededPunchlineRoom([
        'status' => PunchlineMatchupStatus::Voting->value,
        'submittedCounts' => [1 => 1, 2 => 1],
        'answers' => [
            ['answerId' => '1-0', 'text' => 'A live tarantula'],
            ['answerId' => '2-0', 'text' => 'A drum set'],
        ],
    ]);
    closeEveryMatchupExceptR11($session);
    $guest1 = $users[1]; // seat 1 -- an author of r1-1, not eligible to vote on it, nothing else pending

    $page = loginAsAndVisitPunchline($guest1, "/punchline?room={$room->code}");

    $page->assertDontSee('A live tarantula')
        ->assertDontSee('A drum set')
        ->assertDontSee('Vote for the funnier answer')
        ->assertSee('Waiting for the rest of the table to finish writing');
});

/**
 * docs/concerns.md: "Ensure that the text fits in every button and does
 * not extend outside the button, and should all be visible without
 * scrolling." A long player-submitted answer (up to 140 chars) rendered as
 * a vote button's label has nowhere to go but overflow past the button's
 * edges under the shared base button rule's white-space: nowrap. Checks
 * the real overflow signal (scrollWidth vs. clientWidth) on a narrow
 * mobile viewport, same as tests/Browser/HoaxRoomBrowserTest.php's own
 * equivalent check for that game's vote buttons.
 */
test('every vote button\'s text stays within its own button on a narrow viewport, never overflowing', function () {
    [$room, $session, $users] = seededPunchlineRoom([
        'status' => PunchlineMatchupStatus::Voting->value,
        'submittedCounts' => [1 => 1, 2 => 1],
        'answers' => [
            ['answerId' => '1-0', 'text' => 'a considerably longer joke answer than the others, written specifically to test button wrapping behavior on a narrow screen'],
            ['answerId' => '2-0', 'text' => 'A drum set'],
        ],
    ]);
    closeEveryMatchupExceptR11($session);
    $host = $users[0];

    $page = loginAsAndVisitPunchline($host, "/punchline?room={$room->code}");
    $page->resize(375, 900);

    $overflowing = $page->script(
        "Array.from(document.querySelectorAll('#punchline-matchups button'))
            .filter(b => b.scrollWidth > b.clientWidth + 1)
            .map(b => b.textContent.trim())"
    );

    expect($overflowing)->toBe([]);
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
        'voteLimit' => 1,
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
