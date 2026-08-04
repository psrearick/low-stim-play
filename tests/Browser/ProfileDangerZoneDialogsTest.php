<?php

use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\Api\PendingAwaitablePage;

uses(RefreshDatabase::class);

/**
 * Covers the Danger Zone panel's two confirm dialogs on /profile -- Clear
 * All Stats and Delete Account (docs/TODO.md's former #1 and #2,
 * docs/DEVLOG.md's "Low-stim confirm dialogs"). As with
 * tests/Browser/FamilyFeudConfirmDialogsTest.php, every click here targets
 * a unique id rather than button text -- both actions' trigger and
 * confirm buttons share the same label, and a plain-text click() hangs
 * rather than erroring when it resolves ambiguously (see that file's
 * docblock for the full story).
 */
function loginAndVisitProfile(User $user): PendingAwaitablePage
{
    $page = visit('/login');

    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('button[type=submit]');

    $page->navigate('/profile');

    return $page;
}

test('Clear All Stats opens a confirm dialog that Cancel closes without deleting anything', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);
    GameParticipant::factory()->create(['user_id' => $user->id]);

    $page = loginAndVisitProfile($user);

    $page->click('#clear-stats-trigger');

    $page->assertSee('Clear all stats?')
        ->click('#clear-stats-confirm-dialog-cancel')
        ->assertDontSee('Clear all stats?');

    expect(GameParticipant::where('user_id', $user->id)->count())->toBe(1);
});

test('confirming Clear All Stats deletes the stats and shows a confirmation message', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);
    GameParticipant::factory()->count(2)->create(['user_id' => $user->id]);

    $page = loginAndVisitProfile($user);

    $page->click('#clear-stats-trigger')
        ->assertSee('Clear all stats?')
        ->click('#clear-stats-confirm')
        ->wait(0.5);

    $page->assertDontSee('Clear all stats?')
        ->assertSee('Your stats have been cleared.');

    expect(GameParticipant::where('user_id', $user->id)->count())->toBe(0);
});

/**
 * Delete Account's own confirm/success path (real logout + redirect home)
 * is covered at the Feature level in tests/Feature/ProfileTest.php --
 * this only covers the dialog's own open/cancel JS behavior, the same
 * class of thing every other test in this file checks.
 */
test('Delete Account opens a confirm dialog that Cancel closes without deleting the account', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAndVisitProfile($user);

    $page->click('#delete-account-trigger');

    $page->assertSee('Delete your account?')
        ->click('#delete-account-confirm-dialog-cancel')
        ->assertDontSee('Delete your account?');

    expect(User::find($user->id))->not->toBeNull();
});
