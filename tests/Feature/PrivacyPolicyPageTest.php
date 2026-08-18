<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * docs/TODO.md's former #13: this app had no privacy policy page anywhere.
 * `docs/specs/analytics.md` section 12's drafted analytics disclosure copy
 * (also reproduced in docs/DEVLOG.md's Analytics section) is reused
 * verbatim here, alongside plain-language coverage of account data,
 * cookies, and the third-party services this app actually uses.
 */
test('the privacy policy page renders with the drafted analytics disclosure and account-data sections', function () {
    $this->get('/privacy')
        ->assertOk()
        ->assertSeeLivewire('pages::privacy')
        ->assertSee('Privacy Policy')
        // The exact drafted copy from docs/specs/analytics.md section 12 --
        // kept verbatim, not paraphrased, since it was already
        // deliberately worded.
        ->assertSee('We count visits to understand which pages are useful.', escape: false)
        ->assertSee('The daily secret is destroyed after 48 hours', escape: false)
        ->assertSee('Account information')
        ->assertSee('Cookies and sessions')
        ->assertSee('Clear All Stats')
        ->assertSee('Delete Account');
});

test('the privacy policy link appears in the site footer for both guests and authenticated users', function () {
    $this->get('/')->assertSee(route('privacy'), escape: false);

    $user = User::factory()->create();
    $this->actingAs($user)->get('/dashboard')->assertSee(route('privacy'), escape: false);
});
