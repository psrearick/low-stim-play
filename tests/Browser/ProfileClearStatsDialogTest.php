<?php

use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\Api\PendingAwaitablePage;

uses(RefreshDatabase::class);

/**
 * Covers the Danger Zone "Clear All Stats" confirm dialog on /profile (see
 * docs/TODO.md's former #1, docs/DEVLOG.md's "Low-stim confirm dialogs").
 * As with tests/Browser/FamilyFeudConfirmDialogsTest.php, every click here
 * targets a unique id rather than button text -- "Clear All Stats" appears
 * twice on screen at once (the trigger and the dialog's own confirm
 * button), and a plain-text click() hangs rather than erroring when it
 * resolves ambiguously (see that file's docblock for the full story).
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
