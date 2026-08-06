<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regression coverage for docs/concerns.md's four Farkle layout reports
 * (#2, #3, #4, #5) -- all CSS/layout bugs that a Livewire-level test can't
 * see, per CLAUDE.md's own testing rules, so each is confirmed against
 * real rendered layout instead of just asserting the markup exists.
 */
it('lets every player row be reached by scrolling the scoreboard on a short mobile viewport', function () {
    // 500px tall -- short enough that #farkle-game's flex chain squeezes
    // .farkle-scoreboard below its natural content height. Before the
    // fix, that squeeze combined with the container's own rounded-corner
    // overflow:hidden silently clipped the lower rows with nothing
    // scrollable to reach them (confirmed live via a real scripted
    // session: scrollHeight/clientHeight both fit the outer document
    // exactly, while the scoreboard's own scrollHeight was taller than
    // its clientHeight).
    $page = visit('/farkle')->resize(390, 500);

    // One click at a time, waiting for each Livewire round trip to land
    // before the next -- firing all 6 clicks back to back raced the
    // stepper's own disabled-state re-render in the full suite (though
    // not in isolation), leaving the final player count nondeterministic.
    for ($i = 0; $i < 6; $i++) {
        $page->click('[aria-label="More players"]');
        $page->wait(0.2);
    }

    expect($page->script("document.querySelector('.stepper-value').textContent.trim()"))->toBe('8');

    $page->click('Start Game')->assertSee('Roll');

    $dimensions = $page->script(
        "(() => { const el = document.querySelector('.farkle-scoreboard'); return {scrollHeight: el.scrollHeight, clientHeight: el.clientHeight}; })()"
    );

    expect($dimensions['scrollHeight'])->toBeGreaterThan($dimensions['clientHeight']);

    $page->script('document.querySelector(".farkle-scoreboard").scrollTop = 9999');

    $lastRowVisible = $page->script(
        "(() => { const container = document.querySelector('.farkle-scoreboard'); const spans = container.querySelectorAll('.grid > span'); const last = spans[spans.length - 2]; const c = container.getBoundingClientRect(); const r = last.getBoundingClientRect(); return r.top >= c.top && r.bottom <= c.bottom; })()"
    );

    expect($lastRowVisible)->toBeTrue();
});

it('keeps banked dice on a single row instead of wrapping and pushing the roll row down', function () {
    $page = visit('/farkle')->resize(1280, 900);

    $page->click('Start Game')->assertSee('Roll');

    // A long multi-roll turn (more than 6 dice banked, since hot dice
    // resets the count) is what used to wrap onto a second line -- set
    // directly via the Livewire component rather than actually playing
    // out a hot-dice streak, which isn't reliably reachable from real
    // random_int() rolls. Looked up by wire:id (via #farkle-game's
    // closest [wire\\:id] ancestor), not window.Livewire.first() --
    // <livewire:rooms-menu/> now renders earlier in the layout on every
    // page, so .first() no longer reliably returns the Farkle page's own
    // component and silently threw a PublicPropertyNotFoundException
    // that hung the whole browser session instead of failing fast.
    $page->script(
        "window.Livewire.find(document.getElementById('farkle-game').closest('[wire\\\\:id]').getAttribute('wire:id')).set('bankedDiceThisTurn', [1,2,3,4,5,6,1,2,3,4,5,6,1,2])"
    );
    $page->wait(0.3);

    $row = $page->script(
        "(() => { const el = document.querySelector('.farkle-banked-row'); return {flexWrap: getComputedStyle(el).flexWrap, scrollWidth: el.scrollWidth, clientWidth: el.clientWidth}; })()"
    );

    expect($row['flexWrap'])->toBe('nowrap');
    expect($row['scrollWidth'])->toBeGreaterThan($row['clientWidth']);
});

it('does not overlap the dice with the roll button in a narrow portrait viewport', function () {
    $page = visit('/farkle')->resize(390, 844);

    $page->click('Start Game')->assertSee('Roll');

    $rects = $page->script(
        "(() => { const dice = [...document.querySelectorAll('.dice-area .row .die')].map(el => el.getBoundingClientRect()); const roll = document.querySelector('#rollBtn').getBoundingClientRect(); return {dice, roll}; })()"
    );

    // Every die must be a real, legible square (not squeezed to a
    // sliver by sharing a nowrap row with the Roll button's reserved
    // width) and none of them may overlap the Roll button's own box.
    foreach ($rects['dice'] as $die) {
        expect($die['width'])->toBeGreaterThan(20);

        $overlapsRoll = $die['left'] < $rects['roll']['right']
            && $die['right'] > $rects['roll']['left']
            && $die['top'] < $rects['roll']['bottom']
            && $die['bottom'] > $rects['roll']['top'];

        expect($overlapsRoll)->toBeFalse();
    }
});

it('shows the live scoreboard in a larger font on desktop', function () {
    $page = visit('/farkle')->resize(1280, 900);

    $page->click('Start Game')->assertSee('Roll');

    $fontSize = $page->script(
        "(() => { const el = document.querySelector('.farkle-scoreboard:not(.farkle-final-scores) .grid > span:nth-child(2)'); return getComputedStyle(el).fontSize; })()"
    );

    // Tailwind's plain text-sm (used unconditionally before this fix) is
    // 14px -- assert meaningfully larger, not an exact value, so this
    // doesn't churn every time the clamp/size is tuned further.
    expect((int) $fontSize)->toBeGreaterThan(14);
});
