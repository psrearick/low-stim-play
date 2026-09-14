<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * docs/concerns.md: a page left open while the phone/tab was backgrounded
 * (screen off, switched to a different app, ...) never updated once
 * foregrounded again -- confirmed during Farkle, but reproduced here
 * against the game-agnostic pages::room.show, since resources/js/app.js's
 * own fix (`Livewire.all().forEach(c => c.$wire.$refresh())` on
 * `visibilitychange`/`pageshow`) is deliberately page-agnostic, not a
 * Farkle-specific patch. Simulates "missed a real-time update while
 * backgrounded" by mutating the room directly in the database -- the same
 * gap a real disconnected Echo/Reverb socket leaves (nothing queues/
 * replays a presence-channel event for a client that wasn't listening the
 * instant it fired) -- rather than depending on a real broadcast round
 * trip, which `BROADCAST_CONNECTION=null` in tests can't produce anyway
 * (same reasoning this app's other presence-channel tests already use).
 */
test('a page left open while the tab was backgrounded reflects the real current state once foregrounded again, without navigating', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $session = GameSession::create([
        'type' => GameType::Farkle,
        'mode' => GameMode::Online,
        'settings' => [],
        'started_at' => now(),
    ]);
    $room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);
    RoomSeat::create(['room_id' => $room->id, 'seat_index' => 0, 'user_id' => $host->id]);

    $this->actingAs($host);

    $page = visit('/room/'.$room->code);

    $page->assertSee('Playing 10,000 right now.');

    // The game ends while this tab is backgrounded -- since nothing ever
    // reaches a disconnected socket, the already-rendered page has no way
    // to know this happened without the fix under test.
    $room->update(['status' => RoomStatus::Finished]);

    // Stands in for the tab actually being foregrounded again -- `document.
    // hidden` is already false in this real (never actually hidden)
    // browser, so this only needs to fire the same event app.js's own
    // listener reacts to, not first fake a transition into the background.
    $page->script("document.dispatchEvent(new Event('visibilitychange'))");

    $page->assertDontSee('Playing 10,000 right now.');
    $page->assertNoJavaScriptErrors();
});
