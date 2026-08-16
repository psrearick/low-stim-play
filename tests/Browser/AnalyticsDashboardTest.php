<?php

use App\Analytics\Models\AnalyticsDailyDimension;
use App\Analytics\Models\AnalyticsDailyTotal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\Api\PendingAwaitablePage;

uses(RefreshDatabase::class);

/**
 * A real login through the actual form -- not actingAs(), which only sets
 * the container's resolved guard user and never touches a real session
 * cookie, so it wouldn't survive into a real browser request the way a
 * genuine login does. See tests/Browser/YahtzeeRoomBrowserTest.php for the
 * same pattern.
 */
function loginAsAndVisitAnalytics(User $user, string $path): PendingAwaitablePage
{
    $page = visit('/login');

    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('#login-submit');

    $page->navigate($path);

    return $page;
}

function seedAnalyticsAdmin(): User
{
    config(['analytics.admin_emails' => 'admin@example.com']);

    return User::factory()->create([
        'email' => 'admin@example.com',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
    ]);
}

test('the dashboard renders seeded figures and the range selector switches ranges', function () {
    $admin = seedAnalyticsAdmin();
    $date = now()->subDay()->toDateString();

    AnalyticsDailyTotal::create(['date' => $date, 'pageviews' => 42, 'visitors' => 17]);
    AnalyticsDailyDimension::create(['date' => $date, 'dimension' => 'path', 'value' => '/', 'pageviews' => 42, 'visitors' => 17]);

    $page = loginAsAndVisitAnalytics($admin, '/analytics');

    $page->assertSee('Analytics')
        ->assertSee('Pageviews')
        ->assertSee('42')
        ->assertSee('Daily visitors (summed)')
        ->assertSee('Pages')
        ->assertNoJavaScriptErrors();

    // wire:click driven range switch -- assert the pressed state actually
    // moved to the new button, not just that the page still renders.
    $page->click('30 days')
        ->assertAttribute('button[wire\\:click="setRange(\'30d\')"]', 'aria-pressed', 'true')
        ->assertAttribute('button[wire\\:click="setRange(\'7d\')"]', 'aria-pressed', 'false');
});

test('a non-admin cannot reach the dashboard', function () {
    $nonAdmin = User::factory()->create([
        'email' => 'nobody@example.com',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
    ]);

    $page = loginAsAndVisitAnalytics($nonAdmin, '/analytics');

    $page->assertSee('Forbidden');
});

test('the dashboard collapses to a single column under 768px', function () {
    $admin = seedAnalyticsAdmin();

    $page = loginAsAndVisitAnalytics($admin, '/analytics');
    $page->resize(390, 844);

    $page->assertSee('Analytics')
        ->assertSee('Pages')
        ->assertNoJavaScriptErrors();
});
