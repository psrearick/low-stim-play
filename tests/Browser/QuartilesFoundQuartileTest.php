<?php

/**
 * Covers the one CSS-driven behavior in this game a Livewire::test() can't
 * see (docs/CLAUDE.md's "Automated Browser Testing" section) -- a found
 * quartile's tiles actually relocating into their own row and picking up a
 * distinct background class in a real rendered page, not just the
 * underlying $foundGroups/$unfoundOrder arrays changing server-side
 * (already covered by tests/Feature/Games/QuartilesGameTest.php).
 *
 * Seed 1 is hand-verified (via a throwaway PuzzleGenerator::generate(1)
 * call) to put "dingiest" at group 0, tiles 0-3 (DI/NG/IE/ST) -- picked
 * for a real, short, unambiguous word so the test can click exact tile
 * ids instead of hunting the page for a valid combination.
 */
test('finding a quartile moves its tiles into their own row with a distinct look', function () {
    $page = visit('/word-blocks?seed=1');

    $page->assertPresent('#tile-0')
        ->assertMissing('#found-group-0');

    $page->click('#tile-0')
        ->click('#tile-1')
        ->click('#tile-2')
        ->click('#tile-3')
        ->click('#quartiles-submit-btn')
        ->assertVisible('#found-group-0')
        ->assertSee('Quad found');

    // The relocated tile keeps its id but picks up the found-quartile
    // class -- confirms this is a real class/DOM change, not just text.
    expect($page->script("document.querySelector('#tile-0').className"))
        ->toContain('quartile-tile-found');

    $page->assertNoJavaScriptErrors();
});
