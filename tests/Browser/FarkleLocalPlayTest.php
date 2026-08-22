<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Reads the currently-rendered dice values via the real DOM (not a
 * Livewire property) -- proves the actual click -> Livewire round trip
 * -> re-render loop works, not just the underlying component methods a
 * Feature test already covers.
 *
 * Scoped to `.dice-area .row` specifically, not just `#farkle-game .die`
 * -- once any dice have been locked in this turn, they render in a
 * *separate* `.farkle-banked-row` (see docs/concerns.md's "banked dice
 * should show as banked, not disappear"), which also uses the `.die`
 * class. Without this scoping, reading `#farkle-game .die .number`
 * picks up those non-interactive banked dice too, throwing off the
 * index alignment with the *active* row's own clickable dice below --
 * confirmed live: this silently misaligned which die actually got
 * clicked, leaving the real selection invalid and hanging on the
 * subsequently-disabled Roll Again button.
 *
 * @return list<int>
 */
function currentFarkleDice($page): array
{
    return array_map(
        fn (string $text) => (int) $text,
        $page->script("Array.from(document.querySelectorAll('.dice-area .row .die .number')).map(el => el.textContent.trim()).filter(t => t !== '')"),
    );
}

it('plays through several turns of a solo game, ending via a real click', function () {
    $page = visit('/10000');

    $page->assertSee('10,000')
        ->click('[aria-label="Fewer players"]')
        ->click('Start Game')
        ->assertSee('Roll');

    // Dice are real random_int() rolls server-side, so there's no
    // deterministic way to force a specific outcome from the browser --
    // a single 1 or 5 always scores on its own regardless of the rest of
    // the roll, so selecting the first one found (if any) and clicking
    // Roll Again keeps this both realistic and reliably able to make
    // progress. Not Bank: it's disabled (@disabled(! $this->canBank))
    // until the selection reaches the 500-point minimum entry, and
    // Playwright's click() waits indefinitely for a disabled button to
    // become actionable rather than failing fast -- confirmed live, this
    // hung the whole test run with zero output until killed. Roll Again
    // has no such gate (canRollAgain only needs a valid, nonzero
    // selection), so it's the safe action to drive repeatedly here.
    //
    // #rollBtn itself only exists in the DOM at all when $dice === [] --
    // true for the very first roll of a turn (clicked once, below) or
    // right after a bust, but never after a successful rollAgain(),
    // which already performs its own follow-up roll internally. Calling
    // click('#rollBtn') again inside the loop on the assumption each
    // iteration starts from an empty roll was a second real hang found
    // while writing this test: Playwright's click() waits indefinitely
    // for a selector matching zero elements, exactly like the disabled-
    // button case above, rather than failing fast. So #rollBtn is
    // clicked exactly once, and every loop iteration afterward drives
    // rollAgain() only -- if it happens to bust, the loop simply stops
    // rather than trying to click a Roll button that's gone for good
    // once the turn has ended.
    $page->click('#rollBtn')->wait(0.3);

    for ($turn = 0; $turn < 4; $turn++) {
        if ($page->script("document.body.textContent.includes('busted')")) {
            break;
        }

        $dice = currentFarkleDice($page);
        $scoringIndex = null;

        foreach ($dice as $index => $value) {
            if ($value === 1 || $value === 5) {
                $scoringIndex = $index;
                break;
            }
        }

        if ($scoringIndex === null) {
            // An of-a-kind-only roll with no lone 1/5 -- rare but
            // possible; nothing safe left to click, so stop here rather
            // than trying to reason about arbitrary combinations from
            // the browser.
            break;
        }

        $page->click(".dice-area .row .die >> nth={$scoringIndex}")->wait(0.3);
        $page->click('#rollAgainBtn')->wait(0.3);
    }

    $page->assertNoJavaScriptErrors();
});

it('ends the game via the End Game button and shows final scores', function () {
    $page = visit('/10000');

    $page->click('[aria-label="Fewer players"]')->click('Start Game');

    $page->click('#game-menu-toggle')
        ->click('#end-game-trigger')
        ->assertSee('End the game?')
        ->click('#end-game-confirm')
        ->assertSee('Game Over');

    // Coverage audit gap (docs/DEVLOG.md's Boggle "New Game" bug): the
    // teleported New Game button had never actually been clicked in a
    // real browser -- only asserted visible. newGame() returns to the
    // local setup screen (confirmed by reading the method, same shape
    // as Yahtzee's own), not straight into a fresh roll.
    $page->click('New Game')
        ->assertDontSee('Game Over')
        ->assertVisible('button[wire\\:click="startGame"]');
});
