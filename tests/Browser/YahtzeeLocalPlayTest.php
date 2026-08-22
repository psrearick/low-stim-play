<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('plays a full solo game from setup through game over', function () {
    $page = visit('/yacht');

    $page->assertSee('Yacht')
        ->click('[aria-label="Fewer players"]')
        ->click('Start Game')
        ->assertSee('Roll');

    // Every category, regardless of which one it actually lands on --
    // dice are real random_int() rolls server-side, so there's no
    // deterministic way to target a specific category from the browser.
    // Rolling once then scoring whichever cell is first pickable exercises
    // the real click -> Livewire round trip -> re-render loop 13 times
    // and reaches the real end-of-game state, not just the individual
    // Livewire component methods a Feature test already covers.
    // docs/concerns.md: tapping a pickable cell now opens a confirm
    // dialog instead of scoring immediately (see #score-confirm-dialog's
    // own "Confirm" button in ⚡play.blade.php) -- undo can no longer
    // correct a bad score pick, so every selection requires confirming
    // first.
    for ($round = 0; $round < 13; $round++) {
        $page->click('#rollBtn')->wait(0.3);
        $page->click('.score-cell.pickable >> nth=0')->wait(0.3);
        $page->assertVisible('#score-confirm-dialog');
        $page->click('#score-confirm-btn')->wait(0.3);
    }

    $page->assertSee('Game Over');

    // Coverage audit gap (docs/DEVLOG.md's Boggle "New Game" bug): the
    // teleported New Game button at the end of a local game had never
    // actually been clicked in a real browser -- only asserted visible.
    // Reuses this test's own already-completed game rather than playing
    // a second one from scratch just to reach this button. Unlike
    // Boggle's own New Game (straight back into a fresh, already-started
    // game), Yahtzee's newGame() returns to the local setup screen --
    // confirmed by reading the method, not assumed. New Game now lives
    // inside the Game Menu dropdown (see docs/DEVLOG.md's navbar
    // redesign entry), so it has to be opened first.
    $page->click('#game-menu-toggle')
        ->click('New Game')
        ->assertDontSee('Game Over')
        ->assertVisible('button[wire\\:click="startGame"]');
});

it('keeps a die visually marked across a re-roll', function () {
    $page = visit('/yacht');

    $page->click('[aria-label="Fewer players"]')->click('Start Game');

    $page->click('#rollBtn')->wait(0.3);
    $page->click('.die >> nth=0');
    $page->assertPresent('.die.kept');

    // A second roll re-renders the whole dice row -- confirms the kept
    // die's own state survived the Livewire morph rather than being
    // clobbered by it (the same class of bug that broke the scoreboard
    // drawer's open/closed state and the yahtzee in-game body class
    // elsewhere in this app -- see docs/DEVLOG.md).
    $page->click('#rollBtn')->wait(0.3);
    $page->assertPresent('.die.kept');
});
