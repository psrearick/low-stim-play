<?php

use App\Enums\GameType;
use App\Models\Friendship;
use App\Models\GameChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Covers the site-wide menu drawer (<x-app-sidebar />) -- originally
 * scoped to Dashboard/Profile/Stats pages only, now rendered on every
 * page as the one consistent site-navigation surface (Daily Puzzles,
 * Stats, Friends, About, Privacy, guest Sign In/Create an Account), per
 * the navbar redesign in docs/DEVLOG.md's "Site-wide design system &
 * chrome". "Edit Profile"/"Logout" stay out of it -- those live in the
 * navbar's own avatar dropdown instead (see tests/Feature/UserMenuTest.php).
 *
 * docs/concerns.md follow-up: the per-game entries here now link straight
 * to gameplay rather than that game's stats page, and "My Stats"/"All
 * Games" moved into their own section after the game links, before
 * Friends. The per-game *stats* links that used to live in this same
 * list moved to <x-stats-sidebar />'s own right-hand drawer instead --
 * see tests/Feature/StatsSidebarTest.php.
 */
function appSidebarHtml(string $content): string
{
    preg_match('#<aside.*?</aside>#s', $content, $matches);

    expect($matches[0] ?? null)->not->toBeNull();

    return $matches[0];
}

test('the sidebar appears on every page type', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $paths = ['/', '/dashboard', '/profile', '/profile/word-grid', '/word-grid/play', '/about'];

    foreach ($paths as $path) {
        $this->get($path)->assertSee('id="app-sidebar"', false);
    }
});

test('the sidebar appears for guests too', function () {
    $this->get('/')->assertSee('id="app-sidebar"', false);
});

test('the sidebar lists Home, Daily Puzzles, Rooms, every game, My Stats/All Games, About, and Privacy', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $aside = appSidebarHtml($response->getContent());

    expect($aside)->toContain('id="sidebar-link-home"')
        ->toContain('Daily Puzzles')
        ->toContain("You're not in any rooms.")
        ->toContain('Join a Room')
        ->toContain('Create a Room')
        ->toContain('My Stats')
        ->toContain('All Games')
        ->toContain('Word Grid')
        ->toContain('Yacht')
        ->toContain('Survey Says')
        ->toContain('10,000')
        ->toContain('About')
        ->toContain('Privacy Policy')
        ->not->toContain('Edit Profile')
        ->not->toContain('Logout');
});

test('each game link in the sidebar points to that game\'s play page, not its stats page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $aside = appSidebarHtml($response->getContent());

    expect($aside)->toContain('id="sidebar-link-boggle" href="'.route('boggle.lobby').'"')
        ->toContain('id="sidebar-link-yahtzee" href="'.route('yahtzee.play').'"')
        ->toContain('id="sidebar-link-family-feud" href="'.route('family-feud.play').'"')
        ->toContain('id="sidebar-link-farkle" href="'.route('farkle.play').'"')
        ->toContain('id="sidebar-link-apple-match" href="'.route('apple-match.play').'"')
        ->toContain('id="sidebar-link-clear-the-deck" href="'.route('clear-the-deck.play').'"')
        ->toContain('id="sidebar-link-quartiles" href="'.route('quartiles.play').'"')
        ->toContain('id="sidebar-link-wordle" href="'.route('wordle.play').'"')
        ->toContain('id="sidebar-link-spelling-bee" href="'.route('spelling-bee.play').'"')
        ->toContain('id="sidebar-link-punchline" href="'.route('punchline.play').'"')
        ->toContain('id="sidebar-link-hoax" href="'.route('hoax.play').'"')
        ->not->toContain(route('profile.boggle'))
        ->not->toContain(route('profile.yahtzee'));
});

test('My Stats and All Games form their own section, after the games and before Friends', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $aside = appSidebarHtml($response->getContent());

    // Games section ends, then the My Stats/All Games section, then
    // Friends -- checked by order rather than just presence, since the
    // whole point of docs/concerns.md's ask was the grouping/position,
    // not just that these links exist somewhere.
    $response->assertSeeInOrder([
        'sidebar-link-hoax',
        'sidebar-link-dashboard',
        'sidebar-link-all-games',
        'sidebar-link-friends',
    ], false);

    expect($aside)->toContain('sidebar-link-dashboard')
        ->toContain('sidebar-link-all-games');
});

test('the Home sidebar link is marked active on the homepage', function () {
    $response = $this->get('/');

    $aside = appSidebarHtml($response->getContent());

    expect(substr_count($aside, 'app-sidebar-link active'))->toBe(1);
    expect($aside)->toContain('sidebar-link-home');
});

test('the sidebar shows Friends, with a badge, for an authenticated visitor with a pending request', function () {
    $user = User::factory()->create();
    $requester = User::factory()->create();
    Friendship::factory()->create(['requester_id' => $requester->id, 'addressee_id' => $user->id]);

    $response = $this->actingAs($user)->get('/');

    $aside = appSidebarHtml($response->getContent());

    expect($aside)->toContain('Friends')
        ->toContain('sidebar-friends-badge');

    $response->assertSeeInOrder(['sidebar-friends-badge', '1']);
});

test('the Friends badge count includes unactioned game challenges alongside pending friend requests', function () {
    $user = User::factory()->create();
    $requester = User::factory()->create();
    $challenger = User::factory()->create();
    Friendship::factory()->create(['requester_id' => $requester->id, 'addressee_id' => $user->id]);
    GameChallenge::factory()->create(['from_user_id' => $challenger->id, 'to_user_id' => $user->id, 'game_type' => GameType::Boggle]);

    $response = $this->actingAs($user)->get('/');

    $aside = appSidebarHtml($response->getContent());

    $response->assertSeeInOrder(['sidebar-friends-badge', '2']);
    expect($aside)->toContain('sidebar-friends-badge');
});

test('no Friends badge is shown when there are no pending friend requests', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $aside = appSidebarHtml($response->getContent());

    expect($aside)->not->toContain('sidebar-friends-badge');
});

test('guests see Sign In/Create an Account in the sidebar instead of Friends', function () {
    $response = $this->get('/');

    $aside = appSidebarHtml($response->getContent());

    expect($aside)->toContain('Sign In')
        ->toContain('Create an Account')
        ->not->toContain('Friends');
});

test('authenticated visitors do not see Sign In/Create an Account in the sidebar', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $aside = appSidebarHtml($response->getContent());

    expect($aside)->not->toContain('Sign In')
        ->not->toContain('Create an Account');
});

test('exactly one sidebar link is marked active on a stats page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/profile/word-grid');

    expect(substr_count($response->getContent(), 'app-sidebar-link active'))->toBe(1);
    $response->assertSee('app-sidebar-link active', false);
});

test('the Daily Puzzles sidebar link is marked active on the daily puzzles page', function () {
    $response = $this->get('/daily-puzzles');

    $aside = appSidebarHtml($response->getContent());

    expect(substr_count($aside, 'app-sidebar-link active'))->toBe(1);
    expect($aside)->toContain('sidebar-link-daily-puzzles');
});

test('guests are redirected to login rather than seeing the dashboard', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});
