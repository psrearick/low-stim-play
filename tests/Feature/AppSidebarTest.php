<?php

use App\Models\Friendship;
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

test('the sidebar lists Daily Puzzles, every game\'s stats, About, and Privacy', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $aside = appSidebarHtml($response->getContent());

    expect($aside)->toContain('Daily Puzzles')
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
