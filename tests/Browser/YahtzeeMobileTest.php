<?php

// resize(), not on()->mobile() -- confirmed live that Playwright's device
// emulation via on()->mobile() silently drops every click in this
// environment, even a plain non-Livewire <a><button>Home</button></a>
// navigation. resize() just sets the viewport and leaves real click
// dispatch alone, which works reliably.
it('shows the dice and Roll button together on a narrow mobile viewport', function () {
    $page = visit('/yahtzee');
    $page->resize(390, 844);

    $page->click('[aria-label="Fewer players"]')->click('Start Game');

    // Regression guard for this session's own bug: Roll used to be pushed
    // onto its own row below the dice (or cut off entirely) on a narrow
    // screen because .dice-area wrapped instead of keeping Roll on the
    // same row -- see docs/DEVLOG.md's Yahtzee section.
    $page->assertVisible('.dice-area')
        ->assertVisible('#rollBtn')
        ->click('#rollBtn')
        ->assertVisible('#rollBtn');
});

it('keeps the fullscreen toggle reachable on a narrow mobile viewport', function () {
    $page = visit('/yahtzee');
    $page->resize(390, 844);

    $page->assertVisible('#chrome-toggle-btn');
});
