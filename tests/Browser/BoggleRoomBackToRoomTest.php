<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The teleported button bar's own "New Game" button used to render
 * disabled-but-visible for any room game (resetGame() is a real no-op for
 * a shared session -- a room's board is fixed for everyone, not
 * reroll-able per player), reading as a broken button rather than a
 * correctly-unavailable one. Fixed by putting "Back to room" -- previously
 * only rendered further down inside the reveal screen's own content, a
 * real, working link -- in the button bar instead, matching every other
 * room game's own pattern (see e.g. pages::farkle.play). This is a real
 * browser test, not just a Livewire one, specifically because this exact
 * button bar has a documented history of real teleport/wire:click bugs
 * (see tests/Browser/BoggleNewGameTest.php's own docblock) -- asserting
 * the markup exists isn't enough to trust a click actually navigates.
 */
it('replaces the disabled New Game button with a working Back to room link in a room game', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $session = GameSession::create([
        'type' => GameType::Boggle,
        'mode' => GameMode::Online,
        'settings' => ['size' => 4, 'seed' => 1, 'minutes' => 3],
        'started_at' => now(),
    ]);
    $room->update(['game_session_id' => $session->id]);

    // Mount() itself restores $ended === true whenever the authenticated
    // user already has a GameParticipant for this session (see its own
    // docblock) -- seeding this directly is simpler and less flaky than
    // driving a real game to completion first.
    GameParticipant::create([
        'game_session_id' => $session->id,
        'user_id' => $host->id,
        'score' => 4,
        'stats' => ['words_found' => 1, 'found_words' => ['cat' => 1], 'total_valid_words' => 20],
    ]);

    $this->actingAs($host);

    $page = visit(route('boggle.play', ['size' => 4, 'minutes' => 3, 'seed' => 1, 'room' => $room->code]));

    $page->assertDontSee('New Game')
        ->assertSee('Back to room')
        ->click('Back to room')
        ->assertPathIs('/room/'.$room->code);
});
