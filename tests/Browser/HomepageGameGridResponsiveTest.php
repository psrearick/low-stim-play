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

    // Confirm it's actually 2 per row, not still 3 or already 1 -- 10
    // cards at 2-per-row is exactly 5 rows.
    expect(gameCardRowCount($page))->toBe(5);
});

test('the homepage game grid drops to a single column on a narrow screen', function () {
    $page = visit('/')->resize(400, 4000);

    $widths = gameCardWidths($page);
    expect(array_unique($widths))->toHaveCount(1);

    // One card per row -- as many rows as there are cards.
    expect(gameCardRowCount($page))->toBe(count($widths));
});
