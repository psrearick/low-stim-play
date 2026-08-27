<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests see sign in and create account links in the top bar', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Sign In')
        ->assertSee('Create an Account')
        ->assertDontSee('Logout');
});

/**
 * "My Stats"/"Log Out" no longer render directly in the homepage's own
 * bar -- they moved into the navbar-wide avatar dropdown (docs/TODO.md's
 * former #24, see tests/Feature/UserMenuTest.php for that component's own
 * coverage), which this just confirms actually renders here too.
 */
test('authenticated users see the avatar account menu instead of Sign In/Create an Account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('id="user-menu-toggle"', false)
        ->assertDontSee('Sign In')
        ->assertDontSee('Create an Account');
});

test('the Family Feud game card actually describes the game, not just the app\'s implementation of it', function () {
    $this->get('/')
        ->assertSee('Two teams guess the most popular answers to survey-style questions.', escape: false)
        // The previous copy explained the AI-judge/single-screen
        // implementation detail instead of what the game itself is --
        // see docs/TODO.md's former #1.
        ->assertDontSee('AI judges guesses server-side');
});

test('the Clear the Deck game card shows the "(Tens)" clarifier', function () {
    $this->get('/')
        ->assertSee('Clear the Deck (Tens)');
});

test('the homepage links to Quads, not the trademarked "Quartiles" name', function () {
    $this->get('/')
        ->assertSee('Quads')
        ->assertDontSee('Quartiles')
        ->assertSeeHtml(route('quartiles.play'));
});

/**
 * Every game-card button's visible text is now short and generic
 * ("Play", "Group Play", "Dice Only" -- docs/DEVLOG.md's "Site-wide
 * design system & chrome" section), which would otherwise leave a
 * screen reader navigating by a buttons list hearing an ambiguous
 * "Play, Play, Play, Play..." with no way to tell which game each one
 * launches. Each button's aria-label restores that context ("Word Grid
 * — Play") without bringing back the visible redundancy.
 */
test('every game-card button has a unique aria-label combining the game name and its own action', function () {
    $html = $this->get('/')->getContent();

    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $buttons = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' game-card-actions ')]/a/button");

    expect($buttons->length)->toBeGreaterThan(0);

    $labels = [];
    foreach ($buttons as $button) {
        $label = $button->getAttribute('aria-label');
        expect($label)->not->toBe('');
        $labels[] = $label;
    }

    // No two buttons share an accessible name -- see this test's own
    // docblock for why that matters.
    expect($labels)->toBe(array_unique($labels));
});

test('logging out from the homepage redirects back to a guest view', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect('/');

    $this->assertGuest();
});
