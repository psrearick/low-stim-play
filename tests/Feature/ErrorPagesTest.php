<?php

/**
 * docs/concerns.md: error pages (404 included) need to use the app's
 * current color scheme and link back to home, rather than Laravel's plain
 * stock default views (no resources/views/errors/ directory existed
 * before this). config(['app.debug' => false]) is needed for every one of
 * these -- this app's own .env has APP_DEBUG=true (and phpunit.xml never
 * overrides it), so without this, Laravel renders its detailed debug trace
 * instead of the real errors.{code} view these tests are actually about.
 */
test('a 404 uses the app\'s real layout, not the stock Laravel error page', function () {
    config(['app.debug' => false]);

    $this->get('/this-route-does-not-exist-at-all')
        ->assertStatus(404)
        ->assertSee('Page Not Found')
        ->assertSee('Go to Home')
        // The layout's own persistent top-bar Home button/link, present on
        // every page via layouts/app.blade.php -- confirms this rendered
        // through the real app shell, not a standalone view.
        ->assertSeeInOrder(['Home', 'Page Not Found'])
        ->assertDontSee('Whoops');
});

test('every error view renders through the app layout with a working home link', function (string $code) {
    config(['app.debug' => false]);

    $html = view("errors.{$code}")->render();

    expect($html)->toContain('Go to Home')
        ->and($html)->toContain(url('/'))
        ->and($html)->toContain('class="heading"');
})->with(['403', '404', '419', '429', '500', '503']);
