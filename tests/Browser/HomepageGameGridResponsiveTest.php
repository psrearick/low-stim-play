<?php

/**
 * Covers TODO Task 25: "Homepage layout, 3 columns, 2 columns, 1 column
 * depending on screen size, all columns the same width." The old
 * `.game-grid` was flex-wrap based, which redistributes each row's own
 * leftover space independently -- cards ended up different widths from
 * row to row depending on how many happened to fit. CSS layout behavior
 * like this can't be verified from a Livewire/HTTP test at all, only a
 * real rendered page, so this checks real `getBoundingClientRect()`
 * geometry at three viewport widths rather than just asserting the CSS
 * class name is present.
 *
 * Confirmed live while fixing this: a bare `repeat(3, 1fr)` grid still
 * produced visibly unequal columns (340/299/244px) despite being "equal
 * fr" on paper -- a bare `1fr` is shorthand for `minmax(auto, 1fr)`, and
 * that `auto` minimum is each track's own content-based floor, which
 * varies here since each column holds a different game per row. Needed
 * `minmax(0, 1fr)` instead to actually get equal columns regardless of
 * content -- this test's per-row equal-width assertions are exactly what
 * would have caught that regression.
 */
function gameCardWidths(mixed $page): array
{
    return $page->script(
        "Array.from(document.querySelectorAll('.game-card')).map(el => Math.round(el.getBoundingClientRect().width))"
    );
}

function gameCardRowCount(mixed $page): int
{
    $tops = $page->script(
        "Array.from(document.querySelectorAll('.game-card')).map(el => Math.round(el.getBoundingClientRect().top))"
    );

    return count(array_unique($tops));
}

test('the homepage game grid renders 3 equal-width columns on a wide screen', function () {
    $page = visit('/')->resize(1400, 2000);

    expect(gameCardRowCount($page))->toBeGreaterThan(1); // more than one card per row
    expect(array_unique(gameCardWidths($page)))->toHaveCount(1);
});

test('the homepage game grid drops to 2 equal-width columns on a medium screen', function () {
    $page = visit('/')->resize(800, 2000);

    $widths = gameCardWidths($page);
    expect(array_unique($widths))->toHaveCount(1);

    // Confirm it's actually 2 per row, not still 3 or already 1 -- 11
    // cards at 2-per-row is 6 rows (the last one a lone odd card out).
    expect(gameCardRowCount($page))->toBe(6);
});

test('the homepage game grid drops to a single column on a narrow screen', function () {
    $page = visit('/')->resize(400, 4000);

    $widths = gameCardWidths($page);
    expect(array_unique($widths))->toHaveCount(1);

    // One card per row -- as many rows as there are cards.
    expect(gameCardRowCount($page))->toBe(count($widths));
});

/**
 * The shared base `button` rule sets `white-space: nowrap`, which
 * assumes a button sized to its own text -- but `.game-card-actions
 * button` forces every game button to the card's full width instead, so
 * a long label (e.g. "Clear the Deck (Tens)") had nowhere to go but
 * overflow past the button's own edges at narrower widths instead of
 * wrapping onto a second line. Checks every real game-card button's
 * `scrollWidth` against its `clientWidth` at three widths -- a plain
 * `assertSee` can't catch this, since the overflowing text is still
 * present and "visible," just rendered outside its own button.
 */
test('every game button\'s label text stays within its own button at every grid width, never overflowing', function () {
    foreach ([1400, 800, 375, 320] as $width) {
        $page = visit('/')->resize($width, 3000);

        $overflowing = $page->script(
            "Array.from(document.querySelectorAll('.game-card-actions button'))
                .filter(b => b.scrollWidth > b.clientWidth + 1)
                .map(b => b.textContent.trim())"
        );

        expect($overflowing)->toBe([]);
    }
});
