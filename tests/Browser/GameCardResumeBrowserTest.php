<?php

use App\Enums\GameType;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * docs/concerns.md #7: a real click-through, not just the Livewire-level
 * coverage in tests/Feature/GameCardResumeTest.php -- this is exactly the
 * "new conditionally-shown element" class of bug CLAUDE.md's own
 * "Automated Browser Testing" section flags a Livewire-only test can't
 * catch (the resume link/button not actually rendering visible, or its
 * href pointing somewhere wrong once real routing is involved).
 *
 * The active-game case below seeds Boggle's own GameSession directly
 * (settings.seed/size/minutes only -- Boggle never uses GameSession.state
 * at all, unlike Farkle/Yahtzee/Family Feud/Best Match/Clear the Deck) and
 * has no RoomSeat concept, so this is the one game whose "already in an
 * active game" fixture doesn't need to hand-match another game's own
 * hydrateFromState() shape or drive a real onRoomStarting() start (which
 * dispatches RoomGameStarting -- a ShouldBroadcastNow event -- and,
 * combined with this environment's Playwright/amphp event loop, reliably
 * hung this test for 100s+ with no output when tried; not root-caused
 * further, just avoided).
 */
test('a member of an active game sees and can click "Resume your seat" straight into it from the home page', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);
    $room = Room::factory()->active()->create();
    $room->addMember($user);
    $session = GameSession::factory()->online()->create([
        'type' => GameType::Boggle,
        'settings' => ['size' => 4, 'minutes' => 0, 'seed' => 123],
    ]);
    $room->update(['game_session_id' => $session->id]);

    $page = visit('/login');
    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('#login-submit');

    $page->navigate('/');
    $page->assertSee('Resume your seat')
        ->click('Resume your seat');

    $page->assertPathIs('/word-grid/play');
});

test('a guest sees no resume link on the home page at all', function () {
    $page = visit('/');

    $page->assertDontSee('Resume your seat')
        ->assertDontSee('Continue setup');
});

test('a room member still waiting through the host\'s setup sees "Continue setup" straight into the room lobby', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);
    $room = Room::factory()->create();
    $room->addMember($user);
    $room->markPendingGame(GameType::Yahtzee);

    $page = visit('/login');
    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('#login-submit');

    $page->navigate('/');
    $page->assertSee('Continue setup')
        ->click('Continue setup');

    $page->assertPathIs('/yacht/room/'.$room->code);
});
