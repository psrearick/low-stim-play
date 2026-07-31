<?php

namespace App\Http\Requests;

use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;

/**
 * Swapped in for `laravel/passkeys`' own request (see
 * `AppServiceProvider::register()`) so passkey sign-ins are always
 * remembered. The `@laravel/passkeys` JS package has no option to send a
 * `remember` field with the verification request, so the vendor default
 * (`$this->boolean('remember', false)`) can never be anything but false --
 * every passkey login would otherwise die with the app's normal session
 * lifetime instead of persisting like a password login with "Remember me"
 * checked. Passkeys are already phishing-resistant public-key credentials,
 * so always remembering them is a reasonable default rather than a
 * user-facing toggle.
 */
class RememberedPasskeyVerificationRequest extends PasskeyVerificationRequest
{
    public function remember(): bool
    {
        return true;
    }
}
