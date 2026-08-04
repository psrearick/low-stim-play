<?php

use App\Models\FamilyFeudQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\Api\PendingAwaitablePage;

uses(RefreshDatabase::class);

/**
 * Covers the low-stim <x-dialog>/<x-confirm-dialog> components introduced
 * to replace Family Feud's native wire:confirm() usages (docs/TODO.md's
 * former #1/#2, folded into one commit since the component has no real
 * caller without them) -- these tests are about the dialog's own open/
 * cancel/confirm JS behavior, not the underlying game logic (skipQuestion/
 * endGame/revealManually are already covered at the Livewire level in
 * tests/Feature/Games/FamilyFeudGameTest.php).
 *
 * All clicks below target CSS id selectors, never button *text* -- Pest's
 * browser plugin resolves a plain-text click() against every text node on
 * the page, including ones inside other, currently-closed <dialog>s (e.g.
 * a second dialog's own "Cancel" button, or the Rules panel's own mention
 * of the word "Options"). When more than one match exists, click() doesn't
 * fail fast: it silently waits on (what turns out to be) the wrong, never-
 * interactable match until the browser timeout, which reads as an
 * indefinite hang rather than a clear error. Unique ids sidestep this
 * entirely.
 *
 * 20 questions (not the room test's 4) are seeded here specifically so
 * skipQuestion() always has a spare question left in the bank -- the
 * default 5-round local game consumes 10, and skipQuestion() silently
 * no-ops (leaving the options panel open) if none are left to swap in.
 */
function startLocalFeudGame(): PendingAwaitablePage
{
    FamilyFeudQuestion::factory()->count(20)->create();

    $page = visit('/family-feud');
    $page->click('Start Game')->wait(0.5);

    return $page;
}

test('Skip Question opens a confirm dialog that Cancel closes without skipping', function () {
    $page = startLocalFeudGame();

    $page->click('#turn-options-toggle')
        ->wait(0.3)
        ->click('#skip-question-trigger');

    $page->assertSee('Skip this question?')
        ->click('#skip-question-confirm-dialog-cancel')
        ->assertDontSee('Skip this question?')
        // Untouched by the cancel -- the options panel (and its trigger)
        // is still open, which skipQuestion() itself would have closed.
        ->assertVisible('#skip-question-trigger');
});

test('confirming Skip Question actually skips and closes the options panel', function () {
    $page = startLocalFeudGame();

    $page->click('#turn-options-toggle')
        ->wait(0.3)
        ->click('#skip-question-trigger')
        ->assertSee('Skip this question?')
        ->click('#skip-question-confirm')
        ->wait(0.5);

    $page->assertDontSee('Skip this question?')
        // skipQuestion() sets turnOptionsOpen = false, so the whole
        // options panel -- not just the dialog -- should be gone.
        ->assertMissing('#skip-question-trigger');
});

test('Reveal opens a confirm dialog that Cancel closes without revealing', function () {
    $page = startLocalFeudGame();

    $page->click('#reveal-trigger');

    $page->assertSee('Reveal answers?')
        ->click('#reveal-confirm-dialog-cancel')
        ->assertDontSee('Reveal answers?')
        ->assertDontSee('Finish this round by eye');
});

test('confirming Reveal shows the manual-scoring panel', function () {
    $page = startLocalFeudGame();

    $page->click('#reveal-trigger')
        ->assertSee('Reveal answers?')
        ->click('#reveal-confirm')
        ->wait(0.5);

    $page->assertDontSee('Reveal answers?')
        ->assertSee('Finish this round by eye');
});

test('End Game opens a confirm dialog that Cancel closes without ending the game', function () {
    $page = startLocalFeudGame();

    $page->click('#end-game-trigger');

    $page->assertSee('End the game?')
        ->click('#end-game-confirm-dialog-cancel')
        ->assertDontSee('End the game?')
        ->assertDontSee('New Game');
});

test('confirming End Game shows the New Game button', function () {
    $page = startLocalFeudGame();

    $page->click('#end-game-trigger')
        ->assertSee('End the game?')
        ->click('#end-game-confirm')
        ->wait(0.5);

    $page->assertDontSee('End the game?')
        ->assertSee('New Game');
});
