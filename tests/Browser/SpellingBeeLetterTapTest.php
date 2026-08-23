<?php

/**
 * Covers the one CSS-driven behavior in this game a Livewire::test() can't
 * see (docs/CLAUDE.md's "Automated Browser Testing" section) -- the center
 * letter actually rendering as a visually distinct, larger square in a
 * real rendered page (not just $center/$letters being correct
 * server-side, already covered by tests/Feature/Games/SpellingBeeGameTest.php).
 * This exact bug class bit this game during its own build: a `<button>`
 * element's intrinsic padding-driven minimum size silently overrode its
 * `w-16`/`w-14` Tailwind width utilities, rendering both the center and
 * outer letters at an identical width despite the classes correctly
 * differing -- a `getBoundingClientRect()`-style geometry check is the
 * only thing that would have caught it (checking the class name would have
 * passed the whole time). Fixed with `p-0` on both button classes.
 *
 * Seed 10 is hand-verified (via a throwaway PuzzleGenerator::generate(10)
 * call) to produce center "k" and letters t,i,c,k,l,e,r -- "click" (c,l,i,
 * c,k) is spellable by tapping the outer C/L/I tiles plus the outer C tile
 * again and the center K tile, so the test can tap real tile ids instead
 * of hunting the page for a valid combination. Re-verify this mapping
 * before assuming a failure here is this test's own bug.
 */
test('the center letter renders as a visually distinct, larger square than the outer letters', function () {
    $page = visit('/word-hive?seed=10');

    $page->assertPresent('#letter-center')
        ->assertPresent('#letter-c');

    $centerWidth = $page->script("document.getElementById('letter-center').getBoundingClientRect().width");
    $outerWidth = $page->script("document.getElementById('letter-c').getBoundingClientRect().width");

    expect($centerWidth)->toBeGreaterThan($outerWidth);

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
