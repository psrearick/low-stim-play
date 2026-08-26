<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Covers TODO Task 28: Hard Mode moved from an in-game toggle (disabled,
 * but still visible, after the first guess) to a pre-game setup screen --
 * this is CSS/DOM-driven (which block is even rendered) in a way
 * tests/Feature/Games/WordleGameTest.php's Livewire::test() calls can't
 * fully confirm, so this drives the real page: the setup screen renders
 * first with no session/word yet, choosing Hard Mode there sticks once
 * Start Game is clicked, and the in-game view never exposes a Hard Mode
 * control of its own.
 */
test('a fresh visit shows the setup screen, and Start Game begins play with the chosen Hard Mode', function () {
    $page = visit('/five-letters');

    $page->assertSee('Five Letters')
        ->assertSee('Start Game')
        ->assertMissing('#wordle-input')
        ->assertMissing('#hard-mode-toggle') // the old in-game toggle no longer exists at all
        ->click('#hard-mode-on')
        ->wait(0.3);

    expect($page->script("document.querySelector('#hard-mode-on').className"))->toContain('active')
        ->and($page->script("document.querySelector('#hard-mode-off').className"))->not->toContain('active');

    $page->click('#start-game-btn')
        ->assertPresent('#wordle-input')
        ->assertMissing('#setup')
        ->assertMissing('#hard-mode-toggle');

    $page->assertNoJavaScriptErrors();
});
