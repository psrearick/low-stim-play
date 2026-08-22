<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameSession;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Covers the CSS-driven behaviors a Livewire::test() can't see (docs/
 * CLAUDE.md's "Automated Browser Testing" section): a guessed tile actually
 * picking up a distinct background/border class per its real correct/
 * present/absent status in a rendered page, and the on-screen keyboard
 * recoloring to match -- both already covered at the data level by
 * tests/Feature/Games/WordleGameTest.php, but not as real DOM/CSS.
 *
 * A GameSession with a fixed `settings.answer` is created directly and
 * visited via the async-challenge `?challenge=` param (see
 * pages::wordle.play's joinChallenge()) rather than relying on any
 * seed-to-word mapping -- AnswerPicker draws from the real curated word
 * pool, so (unlike Word Blocks' PuzzleGenerator) there's no stable seed
 * that can be hand-verified to always produce the same answer.
 */
test('a submitted guess colors each tile by its real correct/present/absent status, and recolors the keyboard to match', function () {
    $session = GameSession::factory()->create([
        'type' => GameType::Wordle,
        'mode' => GameMode::Solo,
        'settings' => ['answer' => 'crane', 'hardMode' => false],
    ]);

    $page = visit("/five-letters?challenge={$session->id}");

    $page->assertPresent('#wordle-input')
        ->type('#wordle-input', 'raise')
        ->click('#wordle-submit-btn')
        // Waits for the guess to actually appear rendered in the grid --
        // the Livewire round trip from clicking Submit isn't instant, and
        // "R" only shows up here once the response has been applied.
        ->assertSee('R');

    // "raise" vs "crane": r present, a present, i absent, s absent, e correct.
    expect($page->script("document.querySelector('#cell-0-0').className"))->toContain('bg-warn/20')
        ->and($page->script("document.querySelector('#cell-0-1').className"))->toContain('bg-warn/20')
        ->and($page->script("document.querySelector('#cell-0-2').className"))->toContain('bg-die')
        ->and($page->script("document.querySelector('#cell-0-3').className"))->toContain('bg-die')
        ->and($page->script("document.querySelector('#cell-0-4').className"))->toContain('bg-accent');

    // The on-screen keyboard recolors to match -- 'e' (correct) and 'r'
    // (present) should carry the same status classes as their tiles above.
    expect($page->script("document.querySelector('#key-e').className"))->toContain('bg-accent')
        ->and($page->script("document.querySelector('#key-r').className"))->toContain('bg-warn/20');

    $page->assertNoJavaScriptErrors();
});

test('winning ends the game and reveals the guesses-to-win message instead of the input form', function () {
    $session = GameSession::factory()->create([
        'type' => GameType::Wordle,
        'mode' => GameMode::Solo,
        'settings' => ['answer' => 'crane', 'hardMode' => false],
    ]);

    $page = visit("/five-letters?challenge={$session->id}");

    $page->type('#wordle-input', 'crane')
        ->click('#wordle-submit-btn')
        ->assertSee('Solved in 1 guess')
        ->assertMissing('#wordle-input')
        ->assertNoJavaScriptErrors();
});
