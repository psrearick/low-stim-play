<?php

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Covers pages::room.show's own <x-confirm-dialog> usages (End Room for
 * the host, Leave Room for everyone else) -- see
 * tests/Browser/FamilyFeudConfirmDialogsTest.php's own docblock for why
 * these need a real browser rather than a Livewire-level test, and why
 * every click below targets a CSS id rather than button text.
 */
test('End Room opens a confirm dialog that Cancel closes without ending it', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $this->actingAs($host);

    $page = visit('/room/'.$room->code);

    $page->click('#end-room-trigger')
        ->assertSee('End this room?')
        ->click('#end-room-confirm-dialog-cancel')
        ->assertDontSee('End this room?');

    expect(Room::find($room->id))->not->toBeNull();
});

test('confirming End Room actually deletes it and navigates away', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $this->actingAs($host);

    $page = visit('/room/'.$room->code);

    $page->click('#end-room-trigger')
        ->assertSee('End this room?')
        ->click('#end-room-confirm')
        ->wait(0.5);

    $page->assertPathIs('/');

    expect(Room::find($room->id))->toBeNull();
});

test('Leave Room opens a confirm dialog that Cancel closes without leaving', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $room->addMember($guest);

    $this->actingAs($guest);

    $page = visit('/room/'.$room->code);

    $page->click('#leave-room-trigger')
        ->assertSee('Leave this room?')
        ->click('#leave-room-confirm-dialog-cancel')
        ->assertDontSee('Leave this room?');

    expect($room->members()->count())->toBe(2);
});

test('confirming Leave Room removes the membership and navigates away', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $room->addMember($guest);

    $this->actingAs($guest);

    $page = visit('/room/'.$room->code);

    $page->click('#leave-room-trigger')
        ->assertSee('Leave this room?')
        ->click('#leave-room-confirm')
        ->wait(0.5);

    $page->assertPathIs('/');

    expect($room->members()->count())->toBe(1);
});
