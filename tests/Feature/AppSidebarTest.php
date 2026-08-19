<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);

/**
 * Covers the new persistent sidebar (docs/TODO.md's former #1, "redesign
 * site navigation") that replaced each Dashboard/Profile/Stats page's own
 * one-off Dashboard-button teleport (or, on the individual game show
 * pages, a plain text link) with one consistent nav surface.
 */
function assertHasSidebar(TestCase $test, string $path): void
{
    // "Edit Profile"/"Log Out" moved out of the sidebar entirely, into the
    // navbar's own avatar dropdown (see tests/Feature/UserMenuTest.php) --
    // docs/TODO.md's former #24. The per-game labels also dropped their
    // " Stats" suffix as part of the same change -- checked against just
    // the <aside> fragment, not the whole page, since e.g. pages::profile.
    // boggle.index's own <h1>/title legitimately still says "Word Grid
    // Stats" (out of scope here, untouched), which a page-wide
    // assertDontSee would otherwise collide with.
    $response = $test->get($path)
        ->assertSee('id="app-sidebar"', false)
        ->assertSee('My Stats')
        ->assertSee('All Games')
        ->assertSee('Word Grid')
        ->assertSee('Yacht')
        ->assertSee('Survey Says')
        ->assertSee('10,000');

    preg_match('#<aside.*?</aside>#s', $response->getContent(), $matches);
    expect($matches[0] ?? null)
        ->not->toBeNull()
        ->not->toContain('Word Grid Stats')
        ->not->toContain('Edit Profile')
        ->not->toContain('Log Out');
}

test('the sidebar appears on every Dashboard/Profile/Stats page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (['/dashboard', '/profile', '/profile/word-grid', '/profile/yacht', '/profile/survey-says', '/profile/10000', '/profile/games'] as $path) {
        assertHasSidebar($this, $path);
    }
});

test('the sidebar does not appear on the homepage', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertDontSee('id="app-sidebar"', false);
});

test('the sidebar does not appear on a game-play page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/word-grid/play')
        ->assertDontSee('id="app-sidebar"', false);
});

test('exactly one sidebar link is marked active on a stats page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/profile/word-grid');

    expect(substr_count($response->getContent(), 'app-sidebar-link active'))->toBe(1);
    $response->assertSee('app-sidebar-link active', false);
});

test('guests are redirected to login rather than seeing the sidebar', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});
