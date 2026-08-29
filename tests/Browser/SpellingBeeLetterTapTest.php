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

/**
 * docs/concerns.md: the merged word display is a plain, non-editable div
 * (see its own docblock in the Blade view), not a real `<input>` --
 * confirmed here rather than just trusted from reading the markup, since
 * "does tapping this actually avoid opening a mobile keyboard" is
 * exactly the class of thing only a real browser can answer. No real
 * `<input>`/`<textarea>` anywhere in the word-entry area means there's
 * nothing for a mobile browser to attach a virtual keyboard to.
 */
test('the current word display is not a real input element', function () {
    $page = visit('/word-hive?seed=10');

    $tag = $page->script("document.getElementById('spelling-bee-current-word').tagName");

    expect($tag)->toBe('DIV');
});

/**
 * docs/concerns.md: "Typing any single letter on the keyboard will
 * automatically be captured and added to the field" -- a real physical
 * keydown, dispatched without ever clicking/focusing the word display
 * first, still spells and submits a word (the page-wide keydown listener
 * in the Blade view's own @script block, not a wire:model'd `<input>`).
 * A 0.3s gap between keys mirrors real human typing speed -- each
 * keypress is its own Livewire round trip (same as a hive tap already
 * was), and firing all 5 with zero delay outraces the network rather
 * than exposing a real bug (confirmed separately: waiting after a
 * zero-delay burst still lands on the correct final word).
 */
test('typing on a physical keyboard spells and submits a word without ever clicking the word display', function () {
    $page = visit('/word-hive?seed=10');

    foreach (str_split('click') as $letter) {
        $page->script("document.dispatchEvent(new KeyboardEvent('keydown', {key: '{$letter}', bubbles: true}))");
        $page->wait(0.3);
    }

    $page->assertSee('CLICK');

    $page->script("document.dispatchEvent(new KeyboardEvent('keydown', {key: 'Enter', bubbles: true}))");

    $page->assertSee('Words found')
        ->assertDontSee('Not a word.');

    $page->assertNoJavaScriptErrors();
});

test('Backspace on a physical keyboard removes the last typed letter', function () {
    $page = visit('/word-hive?seed=10');

    $page->script("document.dispatchEvent(new KeyboardEvent('keydown', {key: 'c', bubbles: true}))");
    $page->wait(0.3);
    $page->script("document.dispatchEvent(new KeyboardEvent('keydown', {key: 'l', bubbles: true}))");
    $page->wait(0.3);
    $page->assertSee('CL');

    $page->script("document.dispatchEvent(new KeyboardEvent('keydown', {key: 'Backspace', bubbles: true}))");
    $page->wait(0.3);

    $page->assertDontSee('CL');

    $page->script("document.dispatchEvent(new KeyboardEvent('keydown', {key: 'Backspace', bubbles: true}))");
    $page->wait(0.3);

    $page->assertSee('Type or tap letters');

    $page->assertNoJavaScriptErrors();
});

/**
 * docs/concerns.md: "too small on mobile... should take about 85% of the
 * screen width". Uses on()->iPhoneSE() rather than resize() -- no clicks
 * in this test, and --bee-hex-w's mobile override is a vw-based clamp(),
 * which CLAUDE.md's browser-testing notes flag as unreliable under a
 * plain resize() versus real device emulation.
 */
test('the honeycomb is about 85% of the screen width on a real mobile viewport', function () {
    $page = visit('/word-hive?seed=10')->on()->iPhoneSE();

    $widths = $page->script(<<<'JS'
        (() => ({
            viewport: window.innerWidth,
            hive: document.querySelector('.hive-row-mid').getBoundingClientRect().width,
        }))()
        JS);

    $ratio = $widths['hive'] / $widths['viewport'];

    expect($ratio)->toBeGreaterThan(0.75)->toBeLessThan(0.95);
});

/**
 * docs/concerns.md: "On mobile, when you submit a word, the page jumps
 * because the word is added to the list." Root cause was almost
 * certainly the real `<input>` losing focus on submit and the mobile
 * on-screen keyboard closing -- now that the word display is a plain,
 * non-focus-dependent div (see its own docblock), there's no keyboard to
 * close on submit. resize(), not device emulation, since this test needs
 * real clicks (see CLAUDE.md's own "resize(), not on()->mobile(), for
 * anything that clicks" gotcha).
 */
test('submitting a word on a mobile-width viewport does not shift the page scroll position', function () {
    $page = visit('/word-hive?seed=10')->resize(390, 844);

    $page->click('#letter-c')
        ->click('#letter-l')
        ->click('#letter-i')
        ->click('#letter-c')
        ->click('#letter-center')
        ->assertSee('CLICK');

    $before = $page->script('window.scrollY');

    $page->click('#spelling-bee-submit-btn')->assertSee('Words found');

    $after = $page->script('window.scrollY');

    expect($after)->toBe($before);
});
