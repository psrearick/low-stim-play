<?php

use App\Models\GameSession;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Covers TODO Task 30: layouts/app.blade.php's Game Menu renders a
 * `.app-sidebar-divider` unconditionally right after the Rules button,
 * but #game-actions (the @teleport target every game's own action
 * buttons land in) is frequently empty -- Wordle only ever renders a
 * button inside `@if ($ended)`, so the divider sat above nothing for the
 * entire length of an active game. This is pure CSS/DOM visibility, the
 * exact class of bug a Livewire::test() call can't see at all (it never
 * renders real CSS), so this drives a real page through both states.
 */
test('the Game Menu divider is hidden while playing, with no action button to divide from', function () {
    $page = visit('/five-letters');

    $page->click('#hard-mode-off')
        ->click('#start-game-btn')
        ->click('#game-menu-toggle');

    expect($page->script(
        "getComputedStyle(document.querySelector('#game-menu-sidebar .app-sidebar-divider')).display"
    ))->toBe('none');

    $page->assertNoJavaScriptErrors();
});

test('the Game Menu divider appears once a real action button (New Game) is present', function () {
    $page = visit('/five-letters');

    $page->click('#hard-mode-off')->click('#start-game-btn')->assertPresent('#wordle-input');

    // Same DB-overwrite trick tests/Feature/Games/WordleGameTest.php's own
    // setWordleAnswer() uses -- AnswerPicker always draws from the real
    // curated pool, so the actual answer has to be pinned to make a win
    // deterministic.
    $session = GameSession::sole();
    $session->update(['settings' => array_merge($session->settings, ['answer' => 'crane'])]);

    $page->type('#wordle-input', 'crane')
        ->click('#wordle-submit-btn')
        ->assertSee('Solved in 1 guess')
        ->click('#game-menu-toggle')
        ->assertSee('New Game');

    expect($page->script(
        "getComputedStyle(document.querySelector('#game-menu-sidebar .app-sidebar-divider')).display"
    ))->toBe('block');

    $page->assertNoJavaScriptErrors();
});
