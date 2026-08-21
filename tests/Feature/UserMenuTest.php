<?php

use App\Models\Friendship;
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

test('no pending-request badge is shown when there are no pending friend requests', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertDontSee('id="user-menu-friends-badge"', false);
});

test('the Friends menu item shows a badge with the count of pending received friend requests, not the avatar itself', function () {
    $user = User::factory()->create();
    $requesterA = User::factory()->create();
    $requesterB = User::factory()->create();
    $sentByMe = User::factory()->create();

    Friendship::factory()->create(['requester_id' => $requesterA->id, 'addressee_id' => $user->id]);
    Friendship::factory()->create(['requester_id' => $requesterB->id, 'addressee_id' => $user->id]);
    // Sent by the user, not received -- shouldn't count.
    Friendship::factory()->create(['requester_id' => $user->id, 'addressee_id' => $sentByMe->id]);

    $response = $this->actingAs($user)->get('/');

    $response->assertSee('id="user-menu-friends-badge"', false)
        ->assertSeeInOrder(['user-menu-friends-badge', '2']);

    // The badge only ever lives inside the dropdown panel, after
    // #user-menu-panel opens -- never on #user-menu-toggle (the avatar)
    // itself, which renders earlier in the markup.
    $content = $response->getContent();
    expect(strpos($content, 'user-menu-panel'))->toBeLessThan(strpos($content, 'user-menu-friends-badge'));
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
