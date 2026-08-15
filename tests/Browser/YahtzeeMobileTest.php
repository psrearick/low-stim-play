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

// docs/concerns.md: with enough players that table.scorecard is wider than
// .card-wrap, .card-wrap's plain `align-items: center` let the table render
// with its left edge already scrolled off-screen at scrollLeft: 0 -- no way
// to scroll left far enough to reach it. That pushed the sticky category
// column (position: sticky; left: 0) to visibly overlap the first player's
// column instead of sitting beside it, hiding whichever player rendered
// underneath. See docs/DEVLOG.md's Yahtzee section.
it('keeps the sticky category column from overlapping the first player column on mobile', function () {
    $page = visit('/yahtzee');
    $page->resize(390, 844);

    for ($i = 0; $i < 6; $i++) {
        $page->click('[aria-label="More players"]');
    }
    $page->click('Start Game');

    $rects = json_decode($page->script(<<<'JS'
        (() => {
            const wrap = document.querySelector('.card-wrap');
            const table = document.querySelector('table.scorecard');
            const cat = document.querySelector('table.scorecard th.category');
            const firstPlayerTh = document.querySelectorAll('table.scorecard th')[1];
            return JSON.stringify({
                scrollLeft: wrap.scrollLeft,
                wrapLeft: wrap.getBoundingClientRect().left,
                tableLeft: table.getBoundingClientRect().left,
                catRight: cat.getBoundingClientRect().right,
                p1Left: firstPlayerTh.getBoundingClientRect().left,
            });
        })()
    JS), associative: true);

    expect($rects['scrollLeft'])->toBe(0);
    expect($rects['tableLeft'])->toBe($rects['wrapLeft']);
    expect($rects['catRight'])->toBeLessThanOrEqual($rects['p1Left']);
});
