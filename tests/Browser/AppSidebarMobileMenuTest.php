<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\Api\PendingAwaitablePage;

uses(RefreshDatabase::class);

/**
 * Covers the mobile off-canvas version of the new sidebar (see
 * resources/views/components/app-sidebar.blade.php and the "App sidebar
 * navigation" section of resources/css/app.css) -- CSS visibility can't
 * be verified by a Feature/HTTP test, so this drives it in a real
 * browser at a real 375px viewport.
 */
function loginAndVisitDashboard(User $user): PendingAwaitablePage
{
    $page = visit('/login');

    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('#login-submit');

    $page->navigate('/dashboard')->resize(375, 800);

    return $page;
}

test('the sidebar is hidden on mobile until the menu button opens it', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAndVisitDashboard($user);

    $page->assertMissing('#app-sidebar.open')
        ->click('#sidebar-menu-toggle');

    $page->assertVisible('#app-sidebar.open');
});

test('clicking a sidebar link closes the mobile menu', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAndVisitDashboard($user);

    $page->click('#sidebar-menu-toggle')
        ->assertVisible('#app-sidebar.open')
        ->click('#sidebar-link-all-games');

    $page->assertMissing('#app-sidebar.open');
});

test('clicking the backdrop closes the mobile menu', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAndVisitDashboard($user);

    $page->click('#sidebar-menu-toggle')
        ->assertVisible('#app-sidebar.open');

    // Not a real click() -- the backdrop covers the whole viewport, but
    // Playwright clicks its geometric center by default, which sits under
    // the sidebar itself (z-index 20 over the backdrop's 19) at this
    // width. That leaves the backdrop never "actionable" at that point,
    // so a real click() hangs waiting for it rather than erroring.
    // Dispatching the click directly still exercises the real listener.
    $page->script("document.getElementById('app-sidebar-backdrop').click()");

    $page->assertMissing('#app-sidebar.open');
});
