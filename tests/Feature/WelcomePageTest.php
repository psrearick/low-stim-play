<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests see sign in and create account links in the top bar', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Sign In')
        ->assertSee('Create an Account')
        ->assertDontSee('My Stats')
        ->assertDontSee('Log Out');
});

test('authenticated users see my stats and log out links in the top bar', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('My Stats')
        ->assertSee('Log Out')
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
