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

test('logging out from the homepage redirects back to a guest view', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect('/');

    $this->assertGuest();
});
