<?php

/**
 * app.js's global touchstart/touchmove listener (see resources/js/app.js)
 * exists to stop iOS Safari's edge-swipe-back gesture from firing when a
 * player drags near the screen edge -- most disruptive on Word Hive, whose
 * honeycomb runs edge-to-edge on narrow screens (see docs/DEVLOG.md).
 * app.css's overscroll-behavior-x: none doesn't touch that gesture at all,
 * so this is pure JS: intercept touchmove only when the drag started
 * within EDGE_PX of an edge and is more horizontal than vertical, leaving
 * normal scrolling (including near an edge) untouched.
 *
 * Real OS-level edge-swipe navigation isn't reproducible in a headless
 * browser test, so this dispatches synthetic TouchEvents and asserts the
 * listener's own preventDefault() decision -- the actual logic under test.
 */
function dispatchEdgeSwipe(mixed $page, int $startX, int $startY, int $endX, int $endY): bool
{
    return $page->script(
        <<<JS
        (() => {
            const target = document.body;
            const touch = (x, y) => new Touch({ identifier: 1, target, clientX: x, clientY: y, pageX: x, pageY: y });

            target.dispatchEvent(new TouchEvent('touchstart', {
                touches: [touch({$startX}, {$startY})],
                targetTouches: [],
                changedTouches: [],
                bubbles: true,
                cancelable: true,
            }));

            const move = new TouchEvent('touchmove', {
                touches: [touch({$endX}, {$endY})],
                targetTouches: [],
                changedTouches: [],
                bubbles: true,
                cancelable: true,
            });
            target.dispatchEvent(move);

            target.dispatchEvent(new TouchEvent('touchend', {
                touches: [],
                targetTouches: [],
                changedTouches: [touch({$endX}, {$endY})],
                bubbles: true,
                cancelable: true,
            }));

            return move.defaultPrevented;
        })()
        JS
    );
}

it('blocks a horizontal drag that starts at the left edge', function () {
    $page = visit('/');

    expect(dispatchEdgeSwipe($page, 10, 300, 80, 305))->toBeTrue();
});

it('blocks a horizontal drag that starts at the right edge', function () {
    $page = visit('/');

    $width = $page->script('window.innerWidth');

    expect(dispatchEdgeSwipe($page, $width - 10, 300, $width - 80, 305))->toBeTrue();
});

it('leaves a horizontal drag alone when it does not start near an edge', function () {
    $page = visit('/');

    expect(dispatchEdgeSwipe($page, 200, 300, 270, 305))->toBeFalse();
});

it('leaves an edge touch alone when the drag is vertical (a scroll), not horizontal', function () {
    $page = visit('/');

    expect(dispatchEdgeSwipe($page, 10, 300, 15, 380))->toBeFalse();
});
