<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

/**
 * docs/TODO.md's former #2: `Features::twoFactorAuthentication()` was
 * disabled in config/fortify.php, so none of Fortify's own 2FA routes
 * existed at all. These exercise the real Fortify endpoints end-to-end
 * (not Livewire) to prove the config/User-model wiring actually works,
 * not just that the config array contains the right entry.
 */
test('two-factor authentication is off for a fresh user', function () {
    $user = User::factory()->create();

    expect($user->two_factor_confirmed_at)->toBeNull();
});

test('a user can enable, confirm, and disable two-factor authentication', function () {
    $user = User::factory()->create();

    $this->actingAs($user);
    $this->postJson('/user/confirm-password', ['password' => 'password'])->assertSuccessful();

    $this->postJson('/user/two-factor-authentication')->assertStatus(200);

    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull();
    expect($user->two_factor_confirmed_at)->toBeNull();

    $code = app(Google2FA::class)->getCurrentOtp(decrypt($user->two_factor_secret));

    $this->postJson('/user/confirmed-two-factor-authentication', ['code' => $code])
        ->assertStatus(200);

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();

    $recoveryCodes = $this->getJson('/user/two-factor-recovery-codes')->json();
    expect($recoveryCodes)->toBeArray()->not->toBeEmpty();

    $this->deleteJson('/user/two-factor-authentication')->assertStatus(200);

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
    expect($user->fresh()->two_factor_secret)->toBeNull();
});

test('enabling two-factor authentication requires a confirmed password session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/user/two-factor-authentication')
        ->assertStatus(423);

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

test('the profile page shows enable two-factor authentication when disabled', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/profile')
        ->assertSee('Enable Two-Factor Authentication')
        ->assertDontSee('Two-factor authentication is enabled.');
});

test('the profile page shows two-factor is enabled once confirmed', function () {
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);

    $this->actingAs($user)
        ->get('/profile')
        ->assertSee('Two-factor authentication is enabled.')
        ->assertSee('Disable Two-Factor Authentication')
        ->assertDontSee('Enable Two-Factor Authentication');
});
