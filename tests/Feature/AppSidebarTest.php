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
    $test->get($path)
        ->assertSee('id="app-sidebar"', false)
        ->assertSee('Dashboard')
        ->assertSee('All Games')
        ->assertSee('Word Grid Stats')
        ->assertSee('Yacht Stats')
        ->assertSee('Survey Says Stats')
        ->assertSee('Edit Profile')
        ->assertSee('Log Out');
}

test('the sidebar appears on every Dashboard/Profile/Stats page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (['/dashboard', '/profile', '/profile/boggle', '/profile/yahtzee', '/profile/family-feud', '/profile/games'] as $path) {
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
        ->get('/boggle/play')
        ->assertDontSee('id="app-sidebar"', false);
});

test('exactly one sidebar link is marked active on a stats page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/profile/boggle');

    expect(substr_count($response->getContent(), 'app-sidebar-link active'))->toBe(1);
    $response->assertSee('app-sidebar-link active', false);
});

test('guests are redirected to login rather than seeing the sidebar', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});
