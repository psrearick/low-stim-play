<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The navbar avatar dropdown (docs/TODO.md's former #24) -- open/close and
 * each of its three links actually navigating/acting, mirroring
 * tests/Browser/RoomsMenuBrowserTest.php's own coverage of the sibling
 * Rooms dropdown it shares its open/close/position JS with
 * (resources/js/app.js's registerNavbarDropdown()).
 */
test('the avatar toggle opens and closes the account menu panel', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/');

    $page->assertMissing('#user-menu-panel:not([hidden])')
        ->click('#user-menu-toggle')
        ->assertVisible('#user-menu-panel');

    $page->click('.heading')
        ->assertMissing('#user-menu-panel:not([hidden])');
});

test('Stats navigates to the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/');
    $page->click('#user-menu-toggle')
        ->click('#user-menu-panel a[href$="/dashboard"]')
        ->wait(0.3);

    $page->assertPathIs('/dashboard');
});

test('Edit Profile navigates to the profile page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/');
    $page->click('#user-menu-toggle')
        ->click('#user-menu-panel a[href$="/profile"]')
        ->wait(0.3);

    $page->assertPathIs('/profile');
});

test('Logout actually logs the user out and returns to the guest homepage', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/');
    $page->click('#user-menu-toggle')
        ->click('#user-menu-logout')
        ->wait(0.3);

    $page->assertPathIs('/')
        ->assertSee('Sign In')
        ->assertMissing('#user-menu-toggle');

    $this->assertGuest();
});

test('a guest visitor sees no avatar toggle at all', function () {
    visit('/')->assertMissing('#user-menu-toggle');
});

test('the dropdown panel stays fully on-screen on a narrow mobile viewport', function () {
    // .button-bar .right has a real, documented history of pushing a
    // fixed right-0 dropdown panel off-screen once it wraps below 900px
    // (docs/DEVLOG.md's Room system section, originally caught for the
    // Rooms menu) -- confirming the shared registerNavbarDropdown()
    // positioning logic still holds for this second consumer too.
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/')->resize(390, 844);

    $page->click('#user-menu-toggle')->wait(0.3);

    $rect = $page->script('document.getElementById("user-menu-panel").getBoundingClientRect()');

    expect($rect['left'])->toBeGreaterThanOrEqual(0)
        ->and($rect['right'])->toBeLessThanOrEqual(390);
});
