<?php

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\Api\PendingAwaitablePage;

uses(RefreshDatabase::class);

/**
 * Covers the real click on a "Switch to X" button in <livewire:game-lobby>
 * (see tests/Feature/GameLobbySwitchGameTest.php for the underlying
 * switchGame()/broadcast/RoomSeat logic) -- a Livewire::test() can prove
 * the action itself works, but not that the button is actually visible
 * and clickable in the real rendered page. No presence-event simulation
 * needed here (unlike this app's other room browser tests): the "Switch
 * to X" buttons only ever check `Auth::id() === $room->host_user_id`, not
 * the presence-driven $players roster, so they render immediately for a
 * logged-in host with no live broadcast required.
 */
function loginAsHostAndVisitRoom(User $host, string $path): PendingAwaitablePage
{
    $page = visit('/login');

    $page->fill('email', $host->email)
        ->fill('password', 'password')
        ->click('button[type=submit]');

    $page->navigate($path);

    return $page;
}

test('clicking a "switch game" button in the Boggle room takes the host to the Yahtzee room, same code', function () {
    $host = User::factory()->create(['password' => bcrypt('password')]);
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $page = loginAsHostAndVisitRoom($host, "/boggle/room/{$room->code}");

    $page->assertSee('Boggle Room')
        ->click('#switch-game-yahtzee')
        ->assertPathIs("/yahtzee/room/{$room->code}");
});

test('clicking a "switch game" button in the Yahtzee room takes the host to the Family Feud room, same code', function () {
    $host = User::factory()->create(['password' => bcrypt('password')]);
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $page = loginAsHostAndVisitRoom($host, "/yahtzee/room/{$room->code}");

    $page->assertSee('Yahtzee Room')
        ->click('#switch-game-family_feud')
        ->assertPathIs("/family-feud/room/{$room->code}");
});

test('a non-host does not see any "switch game" buttons', function () {
    $host = User::factory()->create(['password' => bcrypt('password')]);
    $guest = User::factory()->create(['password' => bcrypt('password')]);
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $page = loginAsHostAndVisitRoom($guest, "/boggle/room/{$room->code}");

    $page->assertSee('Boggle Room')
        ->assertDontSee('play something else in this room');
});
