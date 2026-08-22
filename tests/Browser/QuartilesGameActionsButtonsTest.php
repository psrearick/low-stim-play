<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Covers the #game-actions button bar's own conditional visibility (docs/
 * CLAUDE.md's "Automated Browser Testing" section) -- a Livewire::test()
 * feature test can inspect $ended directly but can't see that the New Game
 * button is actually hidden/shown in the rendered page.
 */
test('End Game is shown during play; New Game only appears once the game has ended', function () {
    $page = visit('/word-blocks');

    $page->click('#game-menu-toggle');

    $page->assertVisible('#end-game-btn')
        ->assertMissing('#new-game-btn')
        ->assertMissing('#restart-game-btn');

    $page->click('#end-game-btn');

    // End Game's own click closes the Game dialog it lives in (see
    // app.js's own comment on that) -- New Game, teleported into that
    // same dialog, needs it reopened first.
    $page->click('#game-menu-toggle')
        ->assertVisible('#new-game-btn')
        ->assertMissing('#end-game-btn');

    $page->assertNoJavaScriptErrors();
});
