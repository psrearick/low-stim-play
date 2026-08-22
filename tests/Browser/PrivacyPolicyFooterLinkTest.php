<?php

/**
 * docs/TODO.md's former #13: adding a fourth item (Privacy Policy) to
 * .site-footer's plain justify-content: space-between row -- no
 * flex-wrap, no explicit shrink handling -- is exactly the class of CSS
 * change CLAUDE.md's browser-testing rules ask to verify live rather than
 * just trust from reading the CSS.
 */
it('keeps the site footer from overflowing horizontally on a narrow phone viewport', function () {
    $page = visit('/')->resize(375, 812);

    $page->assertSee('Privacy Policy');

    $info = $page->script(
        "(() => { const f = document.querySelector('.site-footer'); return { scrollWidth: f.scrollWidth, viewport: window.innerWidth }; })()"
    );

    expect($info['scrollWidth'])->toBeLessThanOrEqual($info['viewport']);
});

it('links from the footer to a working privacy policy page', function () {
    $page = visit('/');

    // A CSS selector scoped to the footer, not a plain text click() -- the
    // site menu drawer (see docs/DEVLOG.md's navbar redesign entry) added
    // its own "Privacy Policy" link too, and Pest's browser plugin doesn't
    // fail fast when a text click() matches more than one element on the
    // page (see e.g. tests/Browser/FamilyFeudConfirmDialogsTest.php's own
    // docblock for this exact class of hang).
    $page->click('.site-footer a[href$="/privacy"]')->assertSee('Privacy Policy');
    $page->assertPathIs('/privacy');
});
