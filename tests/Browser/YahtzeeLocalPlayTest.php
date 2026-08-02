<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('plays a full solo game from setup through game over', function () {
    $page = visit('/yahtzee');

    $page->assertSee('Yahtzee')
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
    for ($round = 0; $round < 13; $round++) {
        $page->click('#rollBtn')->wait(0.3);
        $page->click('.score-cell.pickable >> nth=0')->wait(0.3);
    }

    $page->assertSee('Game Over');
});

it('keeps a die visually marked across a re-roll', function () {
    $page = visit('/yahtzee');

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
