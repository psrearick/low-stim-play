<?php

it('renders the homepage with no console or JavaScript errors', function () {
    $page = visit('/');

    $page->assertSee('Low Stim Play')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

it('shows sign in and create account links for a guest', function () {
    $page = visit('/');

    $page->assertSee('Sign In')
        ->assertSee('Create an Account');
});
