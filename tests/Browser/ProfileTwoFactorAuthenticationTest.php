<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\Api\PendingAwaitablePage;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

/**
 * docs/TODO.md's former #2: covers the real click-through enable -> confirm
 * password -> scan QR -> enter code -> recovery codes -> disable flow on
 * /profile, the same discipline ProfileDangerZoneDialogsTest.php already
 * applies to the Danger Zone's dialogs -- a Livewire-level test can't see
 * any of this, since none of Fortify's 2FA endpoints or the vanilla-JS
 * dialogs driving them go through Livewire at all.
 */
function loginAndVisitProfileForTwoFactor(User $user): PendingAwaitablePage
{
    $page = visit('/login');

    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('#login-submit');

    $page->navigate('/profile');

    return $page;
}

test('a user can enable two-factor authentication end to end through the real UI', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAndVisitProfileForTwoFactor($user);

    $page->assertSee('Enable Two-Factor Authentication')
        ->click('#two-factor-enable-trigger');

    // First attempt is unconfirmed -- the password dialog should appear.
    $page->assertSee('Confirm your password')
        ->fill('passkey-password-input', 'password')
        ->click('#passkey-password-submit')
        ->wait(0.5);

    // Confirming password should have retried the enable call and opened
    // the setup dialog with a real QR code rendered.
    $page->assertSee('Set up two-factor authentication');

    $hasQrSvg = $page->script(
        "document.querySelector('#two-factor-qr-code svg') !== null"
    );
    expect($hasQrSvg)->toBeTrue();

    $secret = decrypt($user->fresh()->two_factor_secret);
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $page->fill('two-factor-confirm-code', $code)
        ->click('#two-factor-confirm-submit')
        ->wait(0.5);

    $page->assertSee('Recovery codes');

    $codeCount = $page->script(
        "document.querySelectorAll('#two-factor-recovery-codes-list li').length"
    );
    expect($codeCount)->toBeGreaterThan(0);

    $page->click('#two-factor-recovery-codes-done')->wait(0.5);

    $page->assertSee('Two-factor authentication is enabled.')
        ->assertDontSee('Enable Two-Factor Authentication');

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

test('a user can disable two-factor authentication through the real UI', function () {
    $secret = app(Google2FA::class)->generateSecretKey();

    $user = User::factory()->create([
        'password' => bcrypt('password'),
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);

    // A user with 2FA already confirmed doesn't log straight in from
    // /login -- Fortify redirects to /two-factor-challenge first, so
    // that has to be completed here too before /profile is reachable.
    $page = visit('/login');

    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('#login-submit')
        ->assertSee('Two-factor authentication');

    $page->fill('code', app(Google2FA::class)->getCurrentOtp($secret))
        ->click('#two-factor-code-submit')
        ->wait(0.5);

    $page->navigate('/profile');

    $page->assertSee('Two-factor authentication is enabled.')
        ->click('#two-factor-disable-trigger')
        ->assertSee('Disable two-factor authentication?')
        ->click('#two-factor-disable-confirm')
        ->wait(0.5);

    // Password wasn't confirmed this session (logging in doesn't confirm
    // it -- only an explicit /user/confirm-password call does), so
    // disabling triggers the same password-confirmation dialog the enable
    // flow above already exercised.
    $page->assertSee('Confirm your password')
        ->fill('passkey-password-input', 'password')
        ->click('#passkey-password-submit')
        ->wait(0.5);

    $page->assertSee('Enable Two-Factor Authentication')
        ->assertDontSee('Two-factor authentication is enabled.');

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});
