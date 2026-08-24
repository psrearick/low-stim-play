<?php

/**
 * Covers the one CSS-driven behavior in this game a Livewire::test() can't
 * see (docs/CLAUDE.md's "Automated Browser Testing" section) -- the
 * honeycomb (docs/concerns.md #8) actually rendering as real, equally
 * sized, touching hexagons in a real rendered page, with the center one
 * visually distinct by color/border rather than size (not just
 * $center/$letters being correct server-side, already covered by
 * tests/Feature/Games/SpellingBeeGameTest.php). A honeycomb only actually
 * tessellates if every cell is the same size -- this game's own earlier
 * layout made the center square modestly bigger instead (see app.css's
 * own .hive-hex rules for why that changed), so this assertion flipped
 * from "center is bigger" to "center is the same size, positioned
 * touching its 6 neighbors with no gap."
 *
 * Seed 10 is hand-verified (via a throwaway PuzzleGenerator::generate(10)
 * call) to produce center "k" and letters t,i,c,k,l,e,r -- "click" (c,l,i,
 * c,k) is spellable by tapping the outer C/L/I tiles plus the outer C tile
 * again and the center K tile, so the test can tap real tile ids instead
 * of hunting the page for a valid combination. Re-verify this mapping
 * before assuming a failure here is this test's own bug.
 */
test('the center letter renders the same size as the outer letters, touching them with no gap', function () {
    $page = visit('/word-hive?seed=10');

    $page->assertPresent('#letter-center')
        ->assertPresent('#letter-c');

    $rects = $page->script(<<<'JS'
        (() => {
            const center = document.getElementById('letter-center').getBoundingClientRect();
            const outer = document.getElementById('letter-c').getBoundingClientRect();
            return JSON.stringify({center, outer});
        })()
        JS);
    $rects = json_decode($rects, true);

    // Same size -- a real honeycomb only tessellates if every cell is.
    expect($rects['center']['width'])->toBe($rects['outer']['width'])
        ->and($rects['center']['height'])->toBe($rects['outer']['height']);

    // Touching (no visible gap) -- #letter-c sits in the honeycomb's
    // middle row, directly left of #letter-center, so its right edge
    // should meet the center hex's left edge with no gap between them.
    expect($rects['outer']['right'])->toBe($rects['center']['left']);

    $page->assertNoJavaScriptErrors();
});

test('tapping letters (including the center twice-removed via a repeated outer letter) spells and scores a real word', function () {
    $page = visit('/word-hive?seed=10');

    $page->click('#letter-c')
        ->click('#letter-l')
        ->click('#letter-i')
        ->click('#letter-c')
        ->click('#letter-center')
        ->assertSee('CLICK')
        ->click('#spelling-bee-submit-btn')
        ->assertSee('Words found')
        ->assertDontSee('Not a word.');

    $page->assertNoJavaScriptErrors();
});

/**
 * docs/concerns.md #9: the "Four Letters (5/11)" per-length progress
 * columns actually rendering in a real page -- CLICK is 5 letters, so
 * finding it should move the "5 Letters (0/N)" column to "5 Letters
 * (1/N)" and list CLICK underneath it, while every other length column
 * still shows a real total with 0 found.
 */
test('finding a word updates its length column\'s found count and lists the word there', function () {
    $page = visit('/word-hive?seed=10');

    $page->assertPresent('#length-col-5')
        ->assertSee('5 Letters (0/');

    $page->click('#letter-c')
        ->click('#letter-l')
        ->click('#letter-i')
        ->click('#letter-c')
        ->click('#letter-center')
        ->click('#spelling-bee-submit-btn');

    $page->assertSee('5 Letters (1/')
        ->assertSee('CLICK');

    $page->assertNoJavaScriptErrors();
});
