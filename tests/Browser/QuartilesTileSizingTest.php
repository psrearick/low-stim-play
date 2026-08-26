<?php

/**
 * Covers the button-padding sizing bug documented in docs/TODO.md's audit
 * (found while building Word Hive, confirmed already present here too): a
 * `<button>` element's own intrinsic padding-driven minimum size silently
 * overrides a smaller explicit `w-*`/`aspect-square` Tailwind size, with no
 * visual error and nothing a class-based test could catch -- only a real
 * getBoundingClientRect() geometry check. Before the fix (adding the
 * `.tile-btn` class, app.css), a tile measured 99x99px instead of the
 * intended 56x56px (`min-h-14 w-14`).
 */
test('a quartile tile renders as the intended 56x56px square, not the padding-inflated size', function () {
    $page = visit('/quads?seed=1');

    $page->assertPresent('#tile-0');

    $rect = $page->script("document.getElementById('tile-0').getBoundingClientRect()");

    expect($rect['width'])->toEqual(56)
        ->and($rect['height'])->toEqual(56);

    $page->assertNoJavaScriptErrors();
});
