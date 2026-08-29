<?php

/**
 * Covers the light/dark theme toggle, moved from the site footer into the
 * bottom of the site menu drawer (docs/concerns.md) -- CSS visibility/
 * class toggling, so a Feature/HTTP test can't verify the real behavior.
 * The toggle's own label/aria-label swap and localStorage persistence
 * logic in app.js ([data-theme-toggle]) didn't change, only where it's
 * rendered -- see resources/views/components/app-sidebar.blade.php.
 */
test('the theme toggle lives in the site menu drawer, not the footer', function () {
    $page = visit('/');

    $page->assertDontSee('Light Theme')
        ->click('#sidebar-menu-toggle')
        ->assertVisible('#app-sidebar.open')
        ->assertSee('Light Theme');
});

test('clicking the theme toggle switches the theme and updates its own label', function () {
    $page = visit('/');

    $page->click('#sidebar-menu-toggle')
        ->assertVisible('#app-sidebar.open');

    expect($page->script("document.documentElement.classList.contains('dark')"))->toBeTrue();

    $page->click('[data-theme-toggle]')->assertSee('Dark Theme');

    expect($page->script("document.documentElement.classList.contains('dark')"))->toBeFalse();
    expect($page->script("localStorage.getItem('theme')"))->toBe('light');

    // Doesn't close the drawer on click -- unlike every navigation link
    // here, toggling the theme isn't leaving the page, and the drawer
    // closing would hide the very repaint the click just caused.
    $page->assertVisible('#app-sidebar.open');
});
