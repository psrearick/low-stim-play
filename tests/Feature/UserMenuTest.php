<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Covers the navbar-wide avatar dropdown (docs/TODO.md's former #24) that
 * replaced the sidebar's own "Edit Profile"/"Log Out" links and the
 * homepage's one-off "My Stats"/"Log Out" bar buttons -- one component,
 * rendered by layouts/app.blade.php itself, so it appears identically on
 * every authenticated page instead of being duplicated per page.
 */
test('a guest (logged out) visitor sees no account menu at all', function () {
    $this->get('/')
        ->assertDontSee('id="user-menu-toggle"', false)
        ->assertDontSee('id="user-menu-panel"', false);
});

test('an authenticated visitor sees the avatar toggle and its Stats/Friends/Edit Profile/Logout links', function () {
    $user = User::factory()->create(['name' => 'Alex Rivera']);

    $this->actingAs($user)
        ->get('/')
        ->assertSee('id="user-menu-toggle"', false)
        ->assertSee('id="user-menu-panel"', false)
        ->assertSee('Stats')
        ->assertSee('Friends')
        ->assertSee('Edit Profile')
        ->assertSee('Logout')
        ->assertSeeHtml(route('dashboard'))
        ->assertSeeHtml(route('friends'))
        ->assertSeeHtml(route('profile'))
        ->assertSeeHtml(route('logout'));
});

test('the avatar menu renders identically on a sidebar page, not just the homepage', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSee('id="user-menu-toggle"', false)
        ->assertSee('id="app-sidebar"', false);
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
