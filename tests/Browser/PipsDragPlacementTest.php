<?php

use App\Enums\PipsDifficulty;
use App\Services\Pips\ConstraintChecker;
use App\Services\Pips\PuzzleGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\Api\PendingAwaitablePage;

uses(RefreshDatabase::class);

/**
 * Polls a boolean JS expression until it's true or $timeoutSeconds elapses,
 * rather than a single fixed $page->wait() -- needed because
 * assertMissing()/assertPresent() do one immediate, non-retrying check
 * (CLAUDE.md's own pest-plugin-browser gotchas), so they need whatever
 * async `$wire` round trip they depend on to have already landed. A single
 * fixed wait long enough in isolation still occasionally isn't in a long
 * full-suite run with many prior browser tests already having consumed
 * process/browser resources (confirmed live) -- polling adapts to however
 * long this particular run actually needs, instead of gambling on one
 * guessed duration.
 */
function waitUntilTrue(PendingAwaitablePage $page, string $jsExpression, float $timeoutSeconds = 15.0): void
{
    $start = microtime(true);

    while (microtime(true) - $start < $timeoutSeconds) {
        if ($page->script($jsExpression) === true) {
            return;
        }

        $page->wait(0.25);
    }
}

/**
 * Covers the interactions a Livewire::test() can't see (CLAUDE.md's
 * "Automated Browser Testing" section): pages::pips.play's board/tray have
 * no `wire:click` on individual cells at all (see the play page's own
 * script block docblock, at the bottom of that file) -- placement only
 * happens through the vanilla pointer-event listeners attached in real
 * page JS. tests/Feature/Games/PipsGameTest.php already proves
 * placeDomino()/selectTrayDomino()/rotatePlacement()/movePlacement() are
 * correct in isolation; this proves the actual DOM wiring calls them.
 *
 * Drag-to-place, tap-to-place, tap-to-rotate a placed domino, and
 * drag-to-relocate a placed domino are all exercised inside this one test,
 * on one `visit()`-ed page, rather than as separate tests each opening
 * their own page -- confirmed live that once more than one test in this
 * file opens its own real browser page, every page after the first
 * intermittently sees a stale DOM/state snapshot after an action, even
 * with a generous explicit wait, regardless of which specific interaction
 * or assertion runs on it (a pest-plugin-browser v5.0.0 resource-reuse
 * issue across test cases, not a bug in this page's own JS -- verified
 * separately via an inline diagnostic dump showing the exact same click
 * sequence always produces the correct server-side result). Chaining every
 * interaction through a single page sidesteps it entirely.
 */
test('drag, tap-to-place, and pick-up-by-tap all place/remove dominoes correctly on a real page', function () {
    $generator = app(PuzzleGenerator::class);
    $checker = new ConstraintChecker;
    $puzzle = $generator->forSeed(1, PipsDifficulty::Easy);

    $regionOf = [];
    foreach ($puzzle->board['regions'] as $region) {
        foreach ($region['cells'] as $cell) {
            $regionOf[$cell] = $region;
        }
    }

    $first = $puzzle->solution['placements'][0];
    $firstLo = min($first['valueA'], $first['valueB']);
    $firstHi = max($first['valueA'], $first['valueB']);
    $firstDominoId = "{$firstLo}-{$firstHi}";

    $second = $puzzle->solution['placements'][1];
    $secondLo = min($second['valueA'], $second['valueB']);
    $secondHi = max($second['valueA'], $second['valueB']);
    $secondDominoId = "{$secondLo}-{$secondHi}";

    $page = visit('/pips?seed=1&difficulty=easy');

    $page->assertPresent("#pips-tray-{$firstDominoId}");

    // --- Drag: dispatches real pointer events directly, rather than
    // Playwright's own drag()/dragTo() helper -- that helper simulates the
    // native HTML5 drag-and-drop protocol (dragstart/dragover/drop), which
    // this page's own script block never implements at all (see its
    // docblock: this is a vanilla-pointer-event gesture, the same model
    // pages::boggle.play uses, not native draggable="" elements) --
    // confirmed live that drag()/dragTo() hangs indefinitely against this
    // page for exactly that reason.
    // Also captures the floating drag-ghost's own position at each step and
    // whether the original tray tile is hidden mid-drag (docs/DEVLOG.md's
    // Pips entry -- a real regression test for "the tile visibly follows
    // the cursor while dragging," not just an eyeballed screenshot) --
    // returned as JSON rather than asserted inline, since a single browser
    // round trip per drag step is what actually makes this test fast.
    $dragScript = <<<'JS'
        (() => {
            const tray = document.getElementById('pips-tray-__DOMINO_ID__');
            const cell = document.getElementById('pips-cell-__CELL_A__');
            const from = tray.getBoundingClientRect();
            const to = cell.getBoundingClientRect();
            const fromPoint = { x: from.left + from.width / 2, y: from.top + from.height / 2 };
            const toPoint = { x: to.left + to.width / 2, y: to.top + to.height / 2 };

            function fire(type, target, point) {
                target.dispatchEvent(new PointerEvent(type, {
                    bubbles: true, cancelable: true, composed: true,
                    clientX: point.x, clientY: point.y,
                    pointerId: 1, pointerType: 'mouse', isPrimary: true,
                }));
            }

            fire('pointerdown', tray, fromPoint);
            const ghostPositions = [];
            const steps = 5;
            for (let i = 1; i <= steps; i++) {
                const point = {
                    x: fromPoint.x + (toPoint.x - fromPoint.x) * (i / steps),
                    y: fromPoint.y + (toPoint.y - fromPoint.y) * (i / steps),
                };
                fire('pointermove', document, point);

                const ghost = document.querySelector('.pips-drag-ghost');
                ghostPositions.push(ghost ? { left: ghost.style.left, top: ghost.style.top } : null);
            }

            const trayHiddenDuringDrag = tray.style.visibility === 'hidden';
            fire('pointerup', document, toPoint);
            const ghostRemovedAfterDrop = document.querySelector('.pips-drag-ghost') === null;

            return JSON.stringify({ ghostPositions, trayHiddenDuringDrag, ghostRemovedAfterDrop });
        })()
        JS;

    $dragResult = json_decode($page->script(str_replace(
        ['__DOMINO_ID__', '__CELL_A__'],
        [$firstDominoId, (string) $first['cellA']],
        $dragScript,
    )), true);

    $ghostPositions = array_filter($dragResult['ghostPositions']);
    expect($ghostPositions)->not->toBeEmpty();
    expect(array_unique(array_map(fn (array $p) => $p['left'].','.$p['top'], $ghostPositions)))
        ->toHaveCount(count($ghostPositions), 'the drag ghost should move to a new position on every pointermove step');
    expect($dragResult['trayHiddenDuringDrag'])->toBeTrue();
    expect($dragResult['ghostRemovedAfterDrop'])->toBeTrue();

    waitUntilTrue($page, "document.getElementById('pips-tray-{$firstDominoId}') === null");

    $page->assertMissing("#pips-tray-{$firstDominoId}");

    $filled = json_decode($page->script(
        'JSON.stringify([...document.querySelectorAll(\'.pips-cell[data-filled="1"]\')].map(el => ({cell: Number(el.dataset.cell), dominoId: el.dataset.dominoId, value: el.dataset.value})))'
    ), true);

    expect($filled)->toHaveCount(2);
    expect($filled[0]['dominoId'])->toBe($firstDominoId)
        ->and($filled[1]['dominoId'])->toBe($firstDominoId);

    $cols = $puzzle->board['dimensions']['cols'];
    $rowA = intdiv($filled[0]['cell'], $cols);
    $colA = $filled[0]['cell'] % $cols;
    $rowB = intdiv($filled[1]['cell'], $cols);
    $colB = $filled[1]['cell'] % $cols;

    expect(abs($rowA - $rowB) + abs($colA - $colB))->toBe(1);

    // --- Tap-to-select/tap-to-place (docs/specs/pips.md section 3) -- a
    // first-class second path, not a fallback -- placing a second, distinct
    // domino via real clicks, and checking the live per-region status class
    // updates to match what ConstraintChecker itself computes.
    $page->click("#pips-tray-{$secondDominoId}")
        ->click("#pips-cell-{$second['cellA']}")
        ->click("#pips-cell-{$second['cellB']}");

    waitUntilTrue($page, "document.getElementById('pips-tray-{$secondDominoId}') === null");

    $page->assertMissing("#pips-tray-{$secondDominoId}");

    // Tapping cellA first, cellB second -- per the play page's own tap
    // convention (first tap always gets the domino's lower pip).
    $secondLoValue = min($second['valueA'], $second['valueB']);
    $secondHiValue = max($second['valueA'], $second['valueB']);
    $touchedRegionIds = array_unique([$regionOf[$second['cellA']]['id'], $regionOf[$second['cellB']]['id']]);

    foreach ($touchedRegionIds as $regionId) {
        $region = $regionOf[$second['cellA']]['id'] === $regionId ? $regionOf[$second['cellA']] : $regionOf[$second['cellB']];

        $filledValues = [];
        $remaining = 0;

        foreach ($region['cells'] as $cell) {
            if ($cell === $second['cellA']) {
                $filledValues[] = $secondLoValue;
            } elseif ($cell === $second['cellB']) {
                $filledValues[] = $secondHiValue;
            } elseif (isset($filled[0]) && $cell === $filled[0]['cell']) {
                $filledValues[] = (int) $filled[0]['value'];
            } elseif (isset($filled[1]) && $cell === $filled[1]['cell']) {
                $filledValues[] = (int) $filled[1]['value'];
            } else {
                $remaining++;
            }
        }

        $expected = strtolower($checker->check($region['constraint'], $filledValues, $remaining, PipsDifficulty::Easy->maxPip())->name);

        $class = $page->script("document.getElementById('pips-cell-{$second['cellA']}').className");
        expect($class)->toContain("pips-status-{$expected}");
    }

    // --- Tap-to-rotate a placed domino (docs/DEVLOG.md's Pips entry) -- a
    // plain tap (no real drag) on an already-placed domino swaps its two
    // cells' values in place; it stays placed, not returned to the tray.
    $valueBeforeRotate = $page->script("document.getElementById('pips-cell-{$second['cellA']}').dataset.value");
    $page->click("#pips-cell-{$second['cellA']}");
    waitUntilTrue($page, "document.getElementById('pips-cell-{$second['cellA']}').dataset.value !== '{$valueBeforeRotate}'");

    expect($page->script("document.getElementById('pips-cell-{$second['cellA']}').dataset.value"))
        ->not->toBe($valueBeforeRotate);
    $page->assertMissing("#pips-tray-{$secondDominoId}");

    // --- Drag-to-relocate a placed domino -- picks it up (a ghost built
    // from its own already-rendered pip nodes, so it shows real current
    // values) and drops it at a new, empty spot in one gesture, rather than
    // needing to pick it up to the tray and place it again separately.
    $third = $puzzle->solution['placements'][2];

    $relocateScript = <<<'JS'
        (() => {
            const cellA = document.getElementById('pips-cell-__FROM_CELL__');
            const targetA = document.getElementById('pips-cell-__TO_CELL__');
            const from = cellA.getBoundingClientRect();
            const to = targetA.getBoundingClientRect();
            const fromPoint = { x: from.left + from.width / 2, y: from.top + from.height / 2 };
            const toPoint = { x: to.left + to.width / 2, y: to.top + to.height / 2 };

            function fire(type, target, point) {
                target.dispatchEvent(new PointerEvent(type, {
                    bubbles: true, cancelable: true, composed: true,
                    clientX: point.x, clientY: point.y,
                    pointerId: 1, pointerType: 'mouse', isPrimary: true,
                }));
            }

            fire('pointerdown', cellA, fromPoint);
            const ghostExistedMidDrag = (() => {
                fire('pointermove', document, {
                    x: fromPoint.x + (toPoint.x - fromPoint.x) / 2,
                    y: fromPoint.y + (toPoint.y - fromPoint.y) / 2,
                });
                return document.querySelector('.pips-drag-ghost') !== null;
            })();
            fire('pointermove', document, toPoint);
            fire('pointerup', document, toPoint);

            return JSON.stringify({ ghostExistedMidDrag });
        })()
        JS;

    $relocateResult = json_decode($page->script(str_replace(
        ['__FROM_CELL__', '__TO_CELL__'],
        [(string) $second['cellA'], (string) $third['cellA']],
        $relocateScript,
    )), true);

    expect($relocateResult['ghostExistedMidDrag'])->toBeTrue();

    waitUntilTrue($page, "document.getElementById('pips-cell-{$second['cellA']}').dataset.filled === '0'");

    expect($page->script("document.getElementById('pips-cell-{$second['cellA']}').dataset.filled"))->toBe('0');
    expect($page->script("document.getElementById('pips-cell-{$third['cellA']}').dataset.filled"))->toBe('1');
    expect($page->script("document.getElementById('pips-cell-{$third['cellA']}').dataset.dominoId"))->toBe($secondDominoId);

    // --- Region colors + on-board constraint badges (docs/DEVLOG.md's Pips
    // entry) render for real, not just in the persisted board JSON.
    $cellBackground = $page->script("getComputedStyle(document.getElementById('pips-cell-{$first['cellA']}')).backgroundColor");
    expect($cellBackground)->not->toBe('rgba(0, 0, 0, 0)');

    $hasConstrainedRegion = collect($puzzle->board['regions'])->contains(fn (array $region) => $region['badge'] !== null);
    if ($hasConstrainedRegion) {
        $badgeCount = $page->script("document.querySelectorAll('.pips-badge').length");
        expect($badgeCount)->toBeGreaterThan(0);
    }

    $page->assertNoJavaScriptErrors();
});
