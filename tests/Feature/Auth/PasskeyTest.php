<?php

use App\Http\Requests\RememberedPasskeyVerificationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;

uses(RefreshDatabase::class);

test('passkey verification requests are always remembered', function () {
    expect(new RememberedPasskeyVerificationRequest)->remember()->toBeTrue();
});

test('the app container is bound to resolve passkey verification requests as always-remembered', function () {
    // Resolved via the raw binding closure (not app()->make()) so this
    // doesn't also trigger FormRequest's automatic validation, which needs
    // a real WebAuthn credential payload this test isn't set up to fake.
    $concrete = app()->getBindings()[PasskeyVerificationRequest::class]['concrete'];

    expect($concrete(app()))->toBeInstanceOf(RememberedPasskeyVerificationRequest::class);
});

test('confirming a password returns to the profile page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/user/confirm-password', [
        'password' => 'password',
    ]);

    $response->assertRedirect('/profile');
});

test('adding a passkey requires password confirmation', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withHeader('Accept', 'application/json')
        ->get('/user/passkeys/options');

    $response->assertStatus(423);
});

test('removing a passkey requires password confirmation', function () {
    $user = User::factory()->create();
    $passkey = $user->passkeys()->create([
        'name' => 'Test device',
        'credential_id' => 'credential-id',
        'credential' => ['type' => 'public-key'],
    ]);

    $response = $this->actingAs($user)
        ->withHeader('Accept', 'application/json')
        ->delete("/user/passkeys/{$passkey->id}");

    $response->assertStatus(423);
    expect($user->passkeys()->count())->toBe(1);
});

test('a passkey can be removed once the password is confirmed', function () {
    $user = User::factory()->create();
    $passkey = $user->passkeys()->create([
        'name' => 'Test device',
        'credential_id' => 'credential-id',
        'credential' => ['type' => 'public-key'],
    ]);

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->unix()])
        ->withHeader('Accept', 'application/json')
        ->delete("/user/passkeys/{$passkey->id}");

    $response->assertOk();
    expect($user->passkeys()->count())->toBe(0);
});

test('a user cannot remove another user\'s passkey', function () {
    $owner = User::factory()->create();
    $passkey = $owner->passkeys()->create([
        'name' => 'Test device',
        'credential_id' => 'credential-id',
        'credential' => ['type' => 'public-key'],
    ]);

    $intruder = User::factory()->create();

    $response = $this->actingAs($intruder)
        ->withSession(['auth.password_confirmed_at' => now()->unix()])
        ->withHeader('Accept', 'application/json')
        ->delete("/user/passkeys/{$passkey->id}");

    $response->assertForbidden();
    expect($owner->passkeys()->count())->toBe(1);
});
