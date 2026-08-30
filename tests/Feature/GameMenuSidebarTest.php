<?php

/**
 * Covers the Game Menu drawer (docs/TODO.md's former Task 33) --
 * converted from a real <x-dialog> modal to an off-canvas sidebar
 * anchored to the right, the same shape as <x-app-sidebar />/
 * <x-stats-sidebar /> (see tests/Feature/AppSidebarTest.php and
 * tests/Feature/StatsSidebarTest.php for those). Rendered whenever a page
 * sets $rules or $fullscreenToggle (layouts/app.blade.php), regardless of
 * auth -- unlike Friends/stats, the Game Menu has no login-gated content.
 * Real click-through/CSS-visibility coverage lives in
 * tests/Browser/GameMenuDividerTest.php and the many other Browser tests
 * that click #game-menu-toggle; this file only covers the server-rendered
 * markup shape, which doesn't need a real browser to verify.
 */
function gameMenuSidebarHtml(string $content): string
{
    preg_match('#<aside[^>]*id="game-menu-sidebar".*?</aside>#s', $content, $matches);

    expect($matches[0] ?? null)->not->toBeNull();

    return $matches[0];
}

test('the game menu toggle and sidebar appear on a page with rules', function () {
    $this->get('/quads')
        ->assertSee('id="game-menu-toggle"', false)
        ->assertSee('id="game-menu-sidebar"', false)
        ->assertSee('id="game-menu-backdrop"', false);
});

test('the game menu sidebar is a plain aside, not a dialog', function () {
    $response = $this->get('/quads');

    $aside = gameMenuSidebarHtml($response->getContent());

    expect($aside)->toContain('class="app-sidebar game-menu-sidebar"')
        ->and($response->getContent())->not->toContain('id="game-menu-dialog"');
});

test('the game menu sidebar does not appear on a page with neither rules nor a fullscreen toggle', function () {
    $this->get('/')
        ->assertDontSee('id="game-menu-toggle"', false)
        ->assertDontSee('id="game-menu-sidebar"', false);
});

test('the game menu sidebar contains a Rules button and the game-actions teleport target', function () {
    $response = $this->get('/quads');

    $aside = gameMenuSidebarHtml($response->getContent());

    expect($aside)->toContain('id="rules-btn"')
        ->toContain('id="game-actions"');
});

test('the game menu toggle has no data-dialog-open attribute', function () {
    $this->get('/quads')->assertDontSee('data-dialog-open="game-menu-dialog"', false);
});
