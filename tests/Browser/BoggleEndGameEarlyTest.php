<?php

use App\Models\GameParticipant;
use App\Models\GameSession;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * docs/TODO.md's former #18: clicking End Game on a timed solo game while
 * time is still on the clock shouldn't be recorded as a finished game.
 * Verified as a real click-through, not just Livewire::test(), since this
 * touches conditional Blade visibility (the "not saved" message, and
 * hiding the challenge-link share box) -- exactly the class of bug
 * CLAUDE.md's Automated Browser Testing section exists to catch.
 */
it('shows a "not saved" message and no share link when a timed game is ended early', function () {
    $page = visit('/word-grid');

    // #end-game-btn, not text -- "End Game" also appears in the Rules
    // dialog's own copy ("Press End Game any time to stop early..."),
    // and Playwright's text-based click() hangs rather than erroring the
    // moment more than one element on the page matches, even one inside a
    // currently-closed <dialog> (see CLAUDE.md's Automated Browser
    // Testing section).
    $page->click('Start Solo Game')
        ->assertVisible('#end-game-btn')
        ->click('#end-game-btn');

    $page->assertSee('not saved to your stats')
        ->assertMissing('[data-copy]');

    expect(GameSession::count())->toBe(0)
        ->and(GameParticipant::count())->toBe(0);
});

it('shows the share link and no "not saved" message for an untimed game', function () {
    $page = visit('/word-grid');

    $page->click('Untimed')
        ->click('Start Solo Game')
        ->assertVisible('#end-game-btn')
        ->click('#end-game-btn');

    $page->assertDontSee('not saved to your stats')
        ->assertVisible('[data-copy]');

    expect(GameSession::count())->toBe(1)
        ->and(GameParticipant::count())->toBe(1);
});
