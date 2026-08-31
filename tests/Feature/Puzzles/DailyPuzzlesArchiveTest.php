<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the archive page renders for a guest, no auth required', function () {
    $this->get('/daily-puzzles')->assertSeeLivewire('pages::daily-puzzles');
});

test('the archive lists 30 days, newest first, with a link into each game for each day', function () {
    $today = now()->startOfDay();

    $response = $this->get('/daily-puzzles')->assertOk();

    $response->assertSee('Today');
    $response->assertSee($today->subDays(29)->format('M j, Y'));

    $response->assertSee(route('boggle.play', ['dailyDate' => now()->startOfDay()->toDateString()]), false);
    $response->assertSee(route('quartiles.play', ['dailyDate' => now()->startOfDay()->toDateString()]), false);
    $response->assertSee(route('spelling-bee.play', ['dailyDate' => now()->startOfDay()->toDateString()]), false);
    // No `, false` here (unlike the single-param URLs above) -- these have
    // two query params, so the rendered href HTML-encodes the "&" between
    // them as "&amp;"; the default $escape=true encodes the expected
    // string the same way before comparing.
    $response->assertSee(route('pips.play', ['dailyDate' => now()->startOfDay()->toDateString(), 'difficulty' => 'easy']));
    $response->assertSee(route('pips.play', ['dailyDate' => now()->startOfDay()->toDateString(), 'difficulty' => 'medium']));
    $response->assertSee(route('pips.play', ['dailyDate' => now()->startOfDay()->toDateString(), 'difficulty' => 'hard']));
});

test('today gets its own prominent play card, separate from the archive list', function () {
    $this->get('/daily-puzzles')
        ->assertOk()
        ->assertSee("Play Today's Word Grid", false)
        ->assertSee("Play Today's Quads", false)
        ->assertSee("Play Today's Word Hive", false)
        ->assertSee("Play Today's Pips (Easy)", false)
        ->assertSee("Play Today's Pips (Medium)", false)
        ->assertSee("Play Today's Pips (Hard)", false);
});

test('the site-wide nav bar links to the archive page, on any page, for a guest', function () {
    $this->get('/')->assertSee(route('daily-puzzles'), false);
});
