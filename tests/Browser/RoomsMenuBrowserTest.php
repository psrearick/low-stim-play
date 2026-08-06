<?php

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * docs/concerns.md #9: the navbar "Rooms" dropdown -- open/close, the Join
 * a Room dialog, and Create a Room actually navigating. All clicks target
 * CSS ids, never button text, matching the reasoning in
 * tests/Browser/FamilyFeudConfirmDialogsTest.php's own docblock.
 */
test('the Rooms button opens and closes the dropdown panel', function () {
    $page = visit('/');

    $page->assertMissing('#rooms-menu-panel:not([hidden])')
        ->click('#rooms-menu-toggle')
        ->assertVisible('#rooms-menu-panel');

    // Clicking elsewhere on the page closes it again.
    $page->click('.heading')
        ->assertMissing('#rooms-menu-panel:not([hidden])');
});

test('Create a Room navigates straight into the new room hub', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/');
    $page->click('#rooms-menu-toggle')
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
    $page->click('#rooms-menu-toggle')
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
    $page->click('#rooms-menu-toggle')
        ->click('#rooms-menu-join-trigger')
        ->type('#join-room-code', 'NOPE12')
        ->click('#join-room-submit')
        ->wait(0.5);

    $page->assertSee('No active room with that code.')
        ->assertPathIs('/');
});

test('a room the user belongs to appears in the dropdown and links to its hub', function () {
    $user = User::factory()->create();
    $room = Room::factory()->create();
    $room->addMember($user);

    $this->actingAs($user);

    $page = visit('/');
    $page->click('#rooms-menu-toggle')
        ->assertSee($room->code)
        ->click($room->code)
        ->wait(0.5);

    $page->assertPathIs('/room/'.$room->code);
});
