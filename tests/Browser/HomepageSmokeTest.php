<?php

it('renders the homepage with no console or JavaScript errors', function () {
    $page = visit('/');

    $page->assertSee('Low Stim Play')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

it('shows sign in and create account links for a guest', function () {
    $page = visit('/');

    // Moved into the site menu drawer as part of the navbar redesign (see
    // docs/DEVLOG.md) -- available from every page now, not just the
    // homepage's own one-off bar slot -- so it has to be opened first.
    $page->click('#sidebar-menu-toggle')
        ->assertSee('Sign In')
        ->assertSee('Create an Account');
});
