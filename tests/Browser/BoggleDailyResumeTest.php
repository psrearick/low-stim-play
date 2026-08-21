<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\Api\PendingAwaitablePage;

uses(RefreshDatabase::class);

/**
 * A real login through the actual form -- not actingAs(), which only sets
 * the container's resolved guard user and never touches a real session
 * cookie, so it wouldn't survive into a real browser request. See
 * tests/Browser/AnalyticsDashboardTest.php for the same pattern.
 */
function loginAsAndVisitDailyBoggle(User $user, string $dailyDate): PendingAwaitablePage
{
    $page = visit('/login');

    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('#login-submit');

    $page->navigate("/word-grid/play?dailyDate={$dailyDate}");

    return $page;
}

/**
 * Confirms the daily-lock UI actually survives a real reload in a browser,
 * not just Livewire::test()'s in-memory component state -- the exact class
 * of gap CLAUDE.md's Automated Browser Testing section exists to catch
 * (this specific reveal-screen branch is conditional Blade visibility, not
 * something a component-level assertion alone proves renders correctly).
 */
it('shows the locked, already-done state on reload instead of a fresh board', function () {
    $user = User::factory()->create(['password' => bcrypt('password'), 'email_verified_at' => now()]);
    $date = now()->startOfDay()->toDateString();

    $page = loginAsAndVisitDailyBoggle($user, $date);

    $page->assertVisible('#end-game-btn')
        ->click('#end-game-btn');

    $page->assertSee('Next daily puzzle in')
        ->assertMissing('[data-copy]')
        ->assertMissing('#end-game-btn');

    $page->navigate("/word-grid/play?dailyDate={$date}");

    $page->assertSee('Next daily puzzle in')
        ->assertMissing('#end-game-btn')
        ->assertSee('More Daily Puzzles');
});
