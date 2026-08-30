<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The global Livewire.interceptRequest() in resources/js/app.js used to
 * redirect home on any failed Livewire request except 422. That over-fired
 * on plain transient failures -- e.g. a RedisException from the rate
 * limiter Livewire's own checksum check runs on every request (see
 * docs/DEVLOG.md) -- that don't mean anything is actually gone, just that
 * one request failed. Narrowed to: only a 404 (the resource is confirmably
 * gone, see RoomEndedMidGameTest.php) redirects home; every other failure
 * is left alone with no redirect and no modal.
 *
 * Calls a Livewire method that doesn't exist to produce a real,
 * uninteresting 500 (Livewire's own MethodNotFoundException, a plain
 * \Exception with no custom render()) without needing to fake an
 * infrastructure failure.
 */
it('leaves a generic 500 alone instead of redirecting home', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $page = visit('/login');
    $page->fill('email', $user->email)->fill('password', 'password')->click('#login-submit');
    $page->navigate('/profile');
    $page->assertSee('Profile');

    $page->script(
        <<<'JS'
        (() => {
            const id = document.querySelector('[wire\\:id]').getAttribute('wire:id');
            const component = window.Livewire.find(id);
            return component.call('thisMethodDoesNotExist').catch(() => {});
        })()
        JS
    );
    $page->wait(1);

    $info = $page->script(
        "(() => ({ errorDialog: document.getElementById('livewire-error') !== null, path: location.pathname }))()"
    );

    expect($info['errorDialog'])->toBeFalse();
    expect($info['path'])->toBe('/profile');
});
