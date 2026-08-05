<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\Api\PendingAwaitablePage;

uses(RefreshDatabase::class);

/**
 * Covers docs/TODO.md's former "stats tables don't scroll on mobile" item:
 * these grid-based tables are wider than a phone screen once every column
 * is visible, so each container needs overflow-x-auto (plus a min-width
 * on the grid itself, so it doesn't just squash columns instead) to
 * become a real horizontal scroller rather than silently clipping content.
 * Feature/HTTP tests can assert the classes are present but not that
 * scrolling actually works -- CSS overflow behavior only exists in a real
 * browser -- so this checks scrollWidth > clientWidth on a real 375px
 * viewport, the same class of CSS-only bug this app has shipped before
 * (see docs/DEVLOG.md's "Site-wide design system & chrome" section).
 */
function loginAndVisit(User $user, string $path): PendingAwaitablePage
{
    $page = visit('/login');

    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('button[type=submit]');

    $page->navigate($path);

    return $page;
}

/**
 * Targets the single .overflow-x-auto scroll container on the page rather
 * than the Tailwind arbitrary-value min-w-[...] class directly -- that
 * class name's brackets need CSS.escape() to use in a real selector, and
 * every page under test here has exactly one such container anyway.
 */
function assertHorizontallyScrollable(mixed $page): void
{
    $overflows = $page->script(<<<'JS'
        (() => {
            const el = document.querySelector(".overflow-x-auto");
            return el ? el.scrollWidth > el.clientWidth : null;
        })()
        JS);

    expect($overflows)->toBeTrue();
}

test('the dashboard recent-games table scrolls horizontally on mobile', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);
    $session = GameSession::factory()->create(['type' => GameType::FamilyFeud, 'mode' => GameMode::Online]);
    GameParticipant::factory()->create(['game_session_id' => $session->id, 'user_id' => $user->id, 'score' => 40]);

    $page = loginAndVisit($user, '/dashboard')->resize(375, 800);

    assertHorizontallyScrollable($page);
});

test('the Boggle All Games table scrolls horizontally on mobile', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);
    $session = GameSession::factory()->create(['type' => GameType::Boggle, 'mode' => GameMode::Solo]);
    GameParticipant::factory()->create(['game_session_id' => $session->id, 'user_id' => $user->id, 'score' => 10]);

    $page = loginAndVisit($user, '/profile/boggle')->resize(375, 800);

    assertHorizontallyScrollable($page);
});

test('the Yahtzee All Games table scrolls horizontally on mobile', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);
    $session = GameSession::factory()->create(['type' => GameType::Yahtzee, 'mode' => GameMode::Local]);
    GameParticipant::factory()->create(['game_session_id' => $session->id, 'user_id' => $user->id, 'score' => 150]);

    $page = loginAndVisit($user, '/profile/yahtzee')->resize(375, 800);

    assertHorizontallyScrollable($page);
});

test('the Family Feud All Games table scrolls horizontally on mobile', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);
    $session = GameSession::factory()->create(['type' => GameType::FamilyFeud, 'mode' => GameMode::Local]);
    GameParticipant::factory()->create(['game_session_id' => $session->id, 'user_id' => $user->id, 'score' => 60]);

    $page = loginAndVisit($user, '/profile/family-feud')->resize(375, 800);

    assertHorizontallyScrollable($page);
});

test('the All Games page table scrolls horizontally on mobile', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);
    $session = GameSession::factory()->create(['type' => GameType::Boggle, 'mode' => GameMode::Solo]);
    GameParticipant::factory()->create(['game_session_id' => $session->id, 'user_id' => $user->id, 'score' => 10]);

    $page = loginAndVisit($user, '/profile/games')->resize(375, 800);

    assertHorizontallyScrollable($page);
});
