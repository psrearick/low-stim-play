<?php

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * docs/concerns.md #9/#8: everything room-related -- a list of rooms the
 * current account belongs to, a "Join a Room" dialog, and "Create a Room"
 * actually navigating. Folded into the site menu drawer rather than kept
 * as its own persistent bar dropdown (docs/concerns.md, 2026-08-22) -- one
 * menu to open instead of two, so every action here starts by opening the
 * drawer (`#sidebar-menu-toggle`) rather than a Rooms-specific toggle.
 * All clicks target CSS ids, never button text, matching the reasoning in
 * tests/Browser/FamilyFeudConfirmDialogsTest.php's own docblock.
 */
test('opening the site menu shows the rooms list', function () {
    $page = visit('/');

    $page->assertDontSee("You're not in any rooms.")
        ->click('#sidebar-menu-toggle')
        ->assertSee("You're not in any rooms.");
});

test('Create a Room navigates straight into the new room hub', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/');
    $page->click('#sidebar-menu-toggle')
        ->click('#rooms-menu-create')
        ->wait(0.5);

    $room = Room::sole();

    // The code itself renders inside a readonly <input value="...">,
    // which assertSee can't see (only rendered text content, not
    // attribute values) -- the path assertion is the meaningful check.
    $page->assertPathIs('/room/'.$room->code);
});

test('Join a Room opens a dialog and joining a valid code navigates into it', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $joiner = User::factory()->create();
    $this->actingAs($joiner);

    $page = visit('/');
    $page->click('#sidebar-menu-toggle')
        ->click('#rooms-menu-join-trigger')
        ->assertSee('Join a Room')
        ->type('#join-room-code', $room->code)
        ->click('#join-room-submit')
        ->wait(0.5);

    $page->assertPathIs('/room/'.$room->code);

    expect($room->members()->where('user_id', $joiner->id)->exists())->toBeTrue();
});

test('joining an invalid code shows an error instead of navigating', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/');
    $page->click('#sidebar-menu-toggle')
        ->click('#rooms-menu-join-trigger')
        ->type('#join-room-code', 'NOPE12')
        ->click('#join-room-submit')
        ->wait(0.5);

    $page->assertSee('No active room with that code.')
        ->assertPathIs('/');
});

test('a room the user belongs to appears in the drawer and links to its hub', function () {
    $user = User::factory()->create();
    $room = Room::factory()->create();
    $room->addMember($user);

    $this->actingAs($user);

    $page = visit('/');
    $page->click('#sidebar-menu-toggle')
        ->assertSee($room->code)
        ->click($room->code)
        ->wait(0.5);

    $page->assertPathIs('/room/'.$room->code);
});
