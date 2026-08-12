<?php

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the hosted-rooms dropdown and jumps into the chosen room lobby', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $this->actingAs($host);

    $page = visit('/10000');
    $page->assertSee('Play in a room you host')
        ->click('#hosted-room-'.$room->code)
        ->wait(0.5);

    $page->assertPathIs('/10000/room/'.$room->code);
});
