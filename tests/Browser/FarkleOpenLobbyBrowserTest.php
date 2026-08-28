<?php

use App\Enums\GameType;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * docs/concerns.md: config and the waiting room used to be the same
 * screen, marking the room pending (and so notifying/allowing others to
 * join) the instant the host's page loaded, before they'd touched a
 * setting. A Feature test can prove the underlying gate logic, but not
 * that the config fields actually disappear and the real <livewire:
 * game-lobby> ready-up UI actually renders in a real browser once
 * Open Lobby is clicked -- see tests/Feature/Games/FarkleRoomTest.php's
 * own equivalent Livewire::test() coverage for the state-only half of
 * this.
 */
it('keeps the room unmarked while configuring, and opens the real lobby once Open Lobby is clicked', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $this->actingAs($host);

    $page = visit('/10000/room/'.$room->code);
    $page->assertSee('Target Score')
        ->assertSee('Open Lobby')
        ->assertDontSee('I\'m ready');

    expect($room->fresh()->pendingGameType())->toBeNull();

    $page->click('#open-lobby-btn');

    $page->assertDontSee('Target Score')
        ->assertSee('I\'m ready');

    expect($room->fresh()->pendingGameType())->toBe(GameType::Farkle);
});
