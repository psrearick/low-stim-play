<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Covers the stats-only right-hand drawer (<x-stats-sidebar />, docs/
 * concerns.md) -- the per-game *stats* links that used to live in
 * <x-app-sidebar /> itself before that drawer's own game entries moved
 * over to gameplay (see tests/Feature/AppSidebarTest.php). Opt-in via
 * `<x-slot:sidebar>1</x-slot:sidebar>`, same pages as before: /dashboard,
 * /profile/games, and every /profile/{game} index/show pair.
 */
function statsSidebarHtml(string $content): string
{
    preg_match('#<aside[^>]*id="stats-sidebar".*?</aside>#s', $content, $matches);

    expect($matches[0] ?? null)->not->toBeNull();

    return $matches[0];
}

test('the stats sidebar and its toggle button appear on every stats page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $paths = ['/dashboard', '/profile/games', '/profile/word-grid', '/profile/yacht'];

    foreach ($paths as $path) {
        $this->get($path)
            ->assertSee('id="stats-sidebar-toggle"', false)
            ->assertSee('id="stats-sidebar"', false);
    }
});

test('the stats sidebar does not appear on non-stats pages', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $paths = ['/', '/profile', '/word-grid/play'];

    foreach ($paths as $path) {
        $this->get($path)
            ->assertDontSee('id="stats-sidebar-toggle"', false)
            ->assertDontSee('id="stats-sidebar"', false);
    }
});

test('the stats sidebar lists My Stats, All Games, and every game\'s stats page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/dashboard');

    $aside = statsSidebarHtml($response->getContent());

    expect($aside)->toContain('id="stats-sidebar-link-dashboard" href="'.route('dashboard').'"')
        ->toContain('id="stats-sidebar-link-all-games" href="'.route('profile.games').'"')
        ->toContain('id="stats-sidebar-link-boggle" href="'.route('profile.boggle').'"')
        ->toContain('id="stats-sidebar-link-yahtzee" href="'.route('profile.yahtzee').'"')
        ->toContain('id="stats-sidebar-link-family-feud" href="'.route('profile.family-feud').'"')
        ->toContain('id="stats-sidebar-link-farkle" href="'.route('profile.farkle').'"')
        ->toContain('id="stats-sidebar-link-apple-match" href="'.route('profile.apple-match').'"')
        ->toContain('id="stats-sidebar-link-clear-the-deck" href="'.route('profile.clear-the-deck').'"')
        ->toContain('id="stats-sidebar-link-quartiles" href="'.route('profile.quartiles').'"')
        ->toContain('id="stats-sidebar-link-wordle" href="'.route('profile.wordle').'"')
        ->toContain('id="stats-sidebar-link-spelling-bee" href="'.route('profile.spelling-bee').'"')
        ->toContain('id="stats-sidebar-link-punchline" href="'.route('profile.punchline').'"')
        ->toContain('id="stats-sidebar-link-hoax" href="'.route('profile.hoax').'"');
});

test('My Stats and All Games sit in their own section, before the per-game stats links', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertSeeInOrder([
        'stats-sidebar-link-dashboard',
        'stats-sidebar-link-all-games',
        'stats-sidebar-link-boggle',
    ], false);
});

test('the current game\'s stats link is marked active in the stats sidebar', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/profile/word-grid');

    $aside = statsSidebarHtml($response->getContent());

    expect(substr_count($aside, 'app-sidebar-link active'))->toBe(1);

    preg_match('#<a id="stats-sidebar-link-boggle".*?</a>#s', $aside, $matches);

    expect($matches[0] ?? null)->not->toBeNull()
        ->and($matches[0])->toContain('app-sidebar-link active');
});
