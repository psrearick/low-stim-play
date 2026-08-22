<?php

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Covers the navbar-wide avatar dropdown. Trimmed to identity/session
 * actions only (Edit Profile, Logout) as part of the navbar redesign (see
 * docs/DEVLOG.md's "Site-wide design system & chrome") -- Stats and
 * Friends moved into the site-wide menu drawer instead (see
 * tests/Feature/AppSidebarTest.php), so the two navbar menus no longer
 * list the same destinations.
 */
function userMenuPanelHtml(string $content): string
{
    preg_match('#<div id="user-menu-panel".*?</div>\s*</div>#s', $content, $matches);

    expect($matches[0] ?? null)->not->toBeNull();

    return $matches[0];
}

test('a guest (logged out) visitor sees no account menu at all', function () {
    $this->get('/')
        ->assertDontSee('id="user-menu-toggle"', false)
        ->assertDontSee('id="user-menu-panel"', false);
});

test('an authenticated visitor sees the avatar toggle and only Edit Profile/Logout inside it', function () {
    $user = User::factory()->create(['name' => 'Alex Rivera']);

    $response = $this->actingAs($user)->get('/')
        ->assertSee('id="user-menu-toggle"', false)
        ->assertSee('id="user-menu-panel"', false);

    $panel = userMenuPanelHtml($response->getContent());

    expect($panel)->toContain('Edit Profile')
        ->toContain('Logout')
        ->not->toContain('Stats')
        ->not->toContain('Friends');
});

test('the avatar menu renders identically on a stats page, not just the homepage', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSee('id="user-menu-toggle"', false)
        ->assertSee('id="app-sidebar"', false);
});

test('no Friends badge appears in the avatar panel even when a request is pending', function () {
    $user = User::factory()->create();
    $requester = User::factory()->create();
    Friendship::factory()->create(['requester_id' => $requester->id, 'addressee_id' => $user->id]);

    $response = $this->actingAs($user)->get('/');

    $panel = userMenuPanelHtml($response->getContent());

    expect($panel)->not->toContain('user-menu-friends-badge');
});

test('the toggle renders <x-avatar>, not a plain icon or the user\'s raw name', function () {
    // <x-avatar>'s own fallback/upload-photo logic is already covered by
    // tests/Feature/ProfileAvatarTest.php -- this only confirms the
    // toggle button actually wires it in (right component, right name/
    // avatarUrl), not that fallback logic itself.
    $user = User::factory()->create(['name' => 'Zoe Nakamura', 'avatar_path' => null]);

    $this->actingAs($user)
        ->get('/')
        ->assertSee('id="user-menu-toggle"', false)
        ->assertSeeHtml('rounded-full bg-bar text-bg')
        ->assertSee('Z');
});
