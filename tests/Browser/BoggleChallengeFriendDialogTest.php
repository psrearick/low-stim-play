<?php

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\Api\PendingAwaitablePage;

uses(RefreshDatabase::class);

/**
 * A real login through the actual form -- not actingAs(), which only sets
 * the container's resolved guard user and never touches a real session
 * cookie. See tests/Browser/AnalyticsDashboardTest.php for the same
 * pattern.
 */
function loginAsAndVisitBoggle(User $user): PendingAwaitablePage
{
    $page = visit('/login');

    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('#login-submit');

    $page->navigate('/word-grid/play?size=4&minutes=0');

    return $page;
}

it('opens the challenge dialog, sends to a friend, and shows a confirmation', function () {
    $user = User::factory()->create(['password' => bcrypt('password'), 'email_verified_at' => now()]);
    $friend = User::factory()->create(['name' => 'Riley']);
    Friendship::factory()->accepted()->create(['requester_id' => $user->id, 'addressee_id' => $friend->id]);

    $page = loginAsAndVisitBoggle($user);

    $page->click('#end-game-btn')
        ->assertVisible('[data-copy]');

    $page->click('Challenge a Friend')
        ->assertSee('Riley')
        ->click('#challenge-friend-send-'.$friend->id);

    $page->assertSee('Sent!');
});
