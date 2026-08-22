<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A real, pre-existing bug (unrelated to any recent feature work, confirmed
 * present before it via a git-worktree bisect) reported by the user: the
 * "New Game" button at the end of a solo Boggle game silently did nothing.
 * Root cause: `@teleport('#game-actions')` (compiled to Alpine's own
 * `x-teleport`) directly wrapped two sibling `@unless`/`@else` buttons
 * (End Game / New Game) with no single stable root -- confirmed live via a
 * raw fetch/XHR intercept that clicking "New Game" fired zero network
 * requests at all, meaning Livewire's wire:click was never even bound to
 * the newly-swapped-in element after the teleport target's content was
 * re-patched. See `docs/DEVLOG.md`'s Boggle section for the full
 * root-cause trail (including a `wire:key` attempt that didn't fix it, and
 * an "always render both buttons, toggle a hidden class" attempt that
 * turned out to silently drop the second button entirely, since Alpine's
 * `x-teleport` only ever moves a template's *first* root child). Fixed by
 * wrapping both buttons in a single, stable `display: contents` span --
 * teleport only ever has that one span to move, and the button swap
 * inside it goes through Livewire's ordinary, well-tested child-morphing
 * instead of the teleport-specific interop path.
 */
it('actually resets the game when New Game is clicked after ending', function () {
    $page = visit('/word-grid');

    $page->click('Untimed')
        ->click('Start Solo Game')
        ->click('#game-menu-toggle')
        ->assertVisible('#end-game-btn')
        ->click('#end-game-btn');

    $page->assertVisible('[data-copy]');

    $page->click('New Game');

    // Back to a fresh, in-progress game -- the End Game button (and its
    // id) is back, and the reveal screen's share-link box is gone.
    $page->assertVisible('#end-game-btn')
        ->assertMissing('[data-copy]');
});
