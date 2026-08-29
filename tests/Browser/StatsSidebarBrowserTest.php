<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\Api\PendingAwaitablePage;

uses(RefreshDatabase::class);

/**
 * Covers the stats-only right-hand drawer's open/close behavior (see
 * resources/views/components/stats-sidebar.blade.php and the "Stats menu
 * drawer" section of resources/css/app.css) -- CSS visibility can't be
 * verified by a Feature/HTTP test, so this drives it in a real browser,
 * mirroring tests/Browser/AppSidebarMobileMenuTest.php's coverage of the
 * sibling left-hand drawer.
 */
function loginAndVisitDashboardForStatsSidebar(User $user): PendingAwaitablePage
{
    $page = visit('/login');

    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('#login-submit');

    $page->navigate('/dashboard');

    return $page;
}

test('the stats sidebar is hidden until the toggle button opens it', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAndVisitDashboardForStatsSidebar($user);

    $page->assertMissing('#stats-sidebar.open')
        ->click('#stats-sidebar-toggle');

    $page->assertVisible('#stats-sidebar.open');
});

test('clicking a stats sidebar link closes the panel', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAndVisitDashboardForStatsSidebar($user);

    $page->click('#stats-sidebar-toggle')
        ->assertVisible('#stats-sidebar.open')
        ->click('#stats-sidebar-link-all-games');

    $page->assertMissing('#stats-sidebar.open')
        ->assertPathIs('/profile/games');
});

test('clicking the backdrop closes the stats sidebar', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAndVisitDashboardForStatsSidebar($user);

    $page->click('#stats-sidebar-toggle')
        ->assertVisible('#stats-sidebar.open');

    // Not a real click() -- same reasoning as the site menu drawer's own
    // backdrop test: Playwright's click() targets the element's
    // geometric center by default, which can sit under the sidebar
    // itself rather than the backdrop depending on viewport width.
    // Dispatching the click directly still exercises the real listener.
    $page->script("document.getElementById('stats-sidebar-backdrop').click()");

    $page->assertMissing('#stats-sidebar.open');
});

test('the stats sidebar toggle does not appear on a non-stats page', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAndVisitDashboardForStatsSidebar($user);

    $page->navigate('/');

    $page->assertMissing('#stats-sidebar-toggle');
});
