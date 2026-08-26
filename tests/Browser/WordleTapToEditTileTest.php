<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameSession;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Covers TODO Task 29: tapping a tile in the current row lets a player fix
 * a single letter instead of backspacing back to it. The underlying
 * selectCell()/pressKey() logic is already covered at the data level by
 * tests/Feature/Games/WordleGameTest.php -- this drives a real click on a
 * real rendered tile to confirm the DOM click handler actually wires up to
 * it (a Livewire::test() call() never proves a real click lands on the
 * right element, same class of gap docs/CLAUDE.md's "Automated Browser
 * Testing" section calls out).
 *
 * Visits via the async-challenge `?challenge=` param (see
 * pages::wordle.play's joinChallenge()) so the game is already started
 * with a fixed, known answer, same pattern WordleGuessTileBrowserTest.php
 * already uses.
 */
test('tapping a tile in the current row overwrites just that letter', function () {
    $session = GameSession::factory()->create([
        'type' => GameType::Wordle,
        'mode' => GameMode::Solo,
        'settings' => ['answer' => 'crane', 'hardMode' => false],
    ]);

    $page = visit("/five-letters?challenge={$session->id}");

    $page->type('#wordle-input', 'apple')
        ->click('#cell-0-1')
        ->assertPresent('#cell-0-1.ring-2')
        ->click('#key-x')
        // assertPresent/assertSee poll until the round trip settles;
        // script() reads the DOM immediately, so it needs an explicit
        // wait here or it can race the click's own async Livewire
        // request and read stale state (confirmed live).
        ->wait(0.3);

    // Reads the grid tile's own rendered text, not the wire:model-bound
    // #wordle-input's DOM value -- Livewire deliberately leaves a
    // deferred wire:model input's live value alone across an unrelated
    // round trip (here, clicking the on-screen key), to avoid clobbering
    // whatever the user might be actively typing elsewhere on the page.
    // The grid tiles have no such binding and fully re-render from the
    // server on every request, so they're the reliable signal here.
    expect(trim($page->script("document.querySelector('#cell-0-0').textContent")))->toBe('a')
        ->and(trim($page->script("document.querySelector('#cell-0-1').textContent")))->toBe('x')
        ->and(trim($page->script("document.querySelector('#cell-0-2').textContent")))->toBe('p')
        ->and(trim($page->script("document.querySelector('#cell-0-3').textContent")))->toBe('l')
        ->and(trim($page->script("document.querySelector('#cell-0-4').textContent")))->toBe('e');

    // The selection should also have auto-advanced to column 2.
    $page->assertPresent('#cell-0-2.ring-2');

    $page->assertNoJavaScriptErrors();
});
