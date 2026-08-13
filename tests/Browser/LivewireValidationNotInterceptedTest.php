<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The global Livewire.interceptRequest() added for docs/concerns.md's
 * flashed-error report (see RoomEndedMidGameTest.php) redirects home on
 * any failed Livewire request -- except status 422, Livewire's own
 * validation-error status. Without that exclusion, a completely normal
 * validation failure (e.g. clearing a required field on /profile) would
 * bounce the user away from the form they were actively filling in,
 * instead of re-rendering it with an inline error the way $errors/@error
 * already do. Confirmed live: without the exclusion this test's own
 * assertions fail (redirected to "/" instead of staying on "/profile"
 * with the error message visible).
 */
it('leaves a normal Livewire validation error on-page instead of redirecting home', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $page = visit('/login');
    $page->fill('email', $user->email)->fill('password', 'password')->click('#login-submit');
    $page->navigate('/profile');

    $page->script(
        <<<'JS'
        (() => {
            const id = document.getElementById('name').closest('[wire\\:id]').getAttribute('wire:id');
            const component = window.Livewire.find(id);
            return component.set('name', '').then(() => component.call('save'));
        })()
        JS
    );
    $page->wait(1);

    $info = $page->script(
        "(() => ({ errorDialog: document.getElementById('livewire-error') !== null, path: location.pathname, sawRequiredMessage: document.body.innerText.toLowerCase().includes('name field is required') }))()"
    );

    expect($info['errorDialog'])->toBeFalse();
    expect($info['path'])->toBe('/profile');
    expect($info['sawRequiredMessage'])->toBeTrue();
});
