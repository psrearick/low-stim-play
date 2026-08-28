<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Models\GameSession;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('shows nothing while the room is still on the same session this page is showing', function () {
    $room = Room::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::Farkle, 'mode' => GameMode::Online]);
    $room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);

    Livewire::test('room-new-game-notice', [
        'roomCode' => $room->code,
        'currentGame' => GameType::Farkle,
        'myGameSessionId' => $session->id,
    ])->assertDontSee('started a new')->assertDontSee('being played right now');
});

test('offers a Watch link once the room moves to a different session of a spectate-capable game', function () {
    $room = Room::factory()->create();
    $oldSession = GameSession::factory()->create(['type' => GameType::Farkle, 'mode' => GameMode::Online]);
    $newSession = GameSession::factory()->create(['type' => GameType::Farkle, 'mode' => GameMode::Online]);
    $room->update(['status' => RoomStatus::Active, 'game_session_id' => $newSession->id]);

    Livewire::test('room-new-game-notice', [
        'roomCode' => $room->code,
        'currentGame' => GameType::Farkle,
        'myGameSessionId' => $oldSession->id,
    ])
        ->assertSee('The host started a new 10,000 game.')
        ->assertSee('Watch')
        ->assertSeeHtml(route('farkle.play', ['room' => $room->code]));
});

test('shows a wait-only message, no link, once the room moves to a game with no spectating', function () {
    $room = Room::factory()->create();
    $oldSession = GameSession::factory()->create(['type' => GameType::Boggle, 'mode' => GameMode::Online]);
    $newSession = GameSession::factory()->create(['type' => GameType::Boggle, 'mode' => GameMode::Online]);
    $room->update(['status' => RoomStatus::Active, 'game_session_id' => $newSession->id]);

    Livewire::test('room-new-game-notice', [
        'roomCode' => $room->code,
        'currentGame' => GameType::Boggle,
        'myGameSessionId' => $oldSession->id,
    ])
        ->assertSee('Word Grid is being played right now')
        ->assertDontSee('Watch')
        ->assertDontSee('<a ', false);
});

test('a game switched to a different type still resolves the correct label and spectating rule', function () {
    $room = Room::factory()->create();
    $oldSession = GameSession::factory()->create(['type' => GameType::Yahtzee, 'mode' => GameMode::Online]);
    $newSession = GameSession::factory()->create(['type' => GameType::Boggle, 'mode' => GameMode::Online]);
    $room->update(['status' => RoomStatus::Active, 'game_session_id' => $newSession->id]);

    Livewire::test('room-new-game-notice', [
        'roomCode' => $room->code,
        'currentGame' => GameType::Yahtzee,
        'myGameSessionId' => $oldSession->id,
    ])->assertSee('Word Grid is being played right now')->assertDontSee('Watch');
});

test('a finished room (no active game) shows nothing', function () {
    $room = Room::factory()->create(['status' => RoomStatus::Finished]);
    $session = GameSession::factory()->create(['type' => GameType::Farkle, 'mode' => GameMode::Online]);
    $room->update(['game_session_id' => $session->id]);

    Livewire::test('room-new-game-notice', [
        'roomCode' => $room->code,
        'currentGame' => GameType::Farkle,
        'myGameSessionId' => $session->id,
    ])->assertDontSee('started a new')->assertDontSee('being played right now');
});

/**
 * Stands in for the real broadcast the same way tests/Feature/
 * RoomShowTest.php's own "another member on the room hub sees a join
 * prompt" test does -- this app's test env runs with
 * BROADCAST_CONNECTION=null, so nothing ever really reaches a second
 * connection during a test. This proves the listener itself does the
 * right thing once the broadcast actually arrives, not just that a
 * freshly-mounted component reads the right initial state.
 */
test('receiving the RoomGameStarting broadcast picks up a session that changed after mount', function () {
    $room = Room::factory()->create();
    $oldSession = GameSession::factory()->create(['type' => GameType::Farkle, 'mode' => GameMode::Online]);
    $room->update(['status' => RoomStatus::Active, 'game_session_id' => $oldSession->id]);

    $component = Livewire::test('room-new-game-notice', [
        'roomCode' => $room->code,
        'currentGame' => GameType::Farkle,
        'myGameSessionId' => $oldSession->id,
    ])->assertDontSee('started a new');

    $newSession = GameSession::factory()->create(['type' => GameType::Farkle, 'mode' => GameMode::Online]);
    $room->update(['game_session_id' => $newSession->id]);

    $component->call('onRoomChanged')->assertSee('The host started a new 10,000 game.');
});

/**
 * The gap docs/concerns.md described directly: previously nothing showed
 * here until a real GameSession existed, forcing everyone to leave the old
 * page before the host's already-in-progress setup became visible to them.
 * Room::pendingGameType()'s own docblock is the cache-backed source this
 * now also reads, same as pages::room.show's own pendingGameType banner.
 */
test('offers a Join link to the lobby while the host is still setting up a new game, before any session exists', function () {
    $room = Room::factory()->create();
    $oldSession = GameSession::factory()->create(['type' => GameType::Farkle, 'mode' => GameMode::Online]);
    $room->update(['status' => RoomStatus::Finished, 'game_session_id' => null]);
    $room->markPendingGame(GameType::Hoax);

    Livewire::test('room-new-game-notice', [
        'roomCode' => $room->code,
        'currentGame' => GameType::Farkle,
        'myGameSessionId' => $oldSession->id,
    ])
        ->assertSee("The host is setting up Hoax -- hasn't started yet.")
        ->assertSee('Join')
        ->assertSeeHtml(route('hoax.room', ['code' => $room->code]));
});

test('a real session in progress takes precedence over a stale pending marker', function () {
    $room = Room::factory()->create();
    $oldSession = GameSession::factory()->create(['type' => GameType::Farkle, 'mode' => GameMode::Online]);
    $newSession = GameSession::factory()->create(['type' => GameType::Farkle, 'mode' => GameMode::Online]);
    $room->update(['status' => RoomStatus::Active, 'game_session_id' => $newSession->id]);
    $room->markPendingGame(GameType::Farkle);

    Livewire::test('room-new-game-notice', [
        'roomCode' => $room->code,
        'currentGame' => GameType::Farkle,
        'myGameSessionId' => $oldSession->id,
    ])
        ->assertSee('The host started a new 10,000 game.')
        ->assertDontSee("hasn't started yet");
});

test('dismissing the notice hides it, but a genuinely new target surfaces again', function () {
    $room = Room::factory()->create();
    $oldSession = GameSession::factory()->create(['type' => GameType::Farkle, 'mode' => GameMode::Online]);
    $room->update(['status' => RoomStatus::Finished, 'game_session_id' => null]);
    $room->markPendingGame(GameType::Hoax);

    $component = Livewire::test('room-new-game-notice', [
        'roomCode' => $room->code,
        'currentGame' => GameType::Farkle,
        'myGameSessionId' => $oldSession->id,
    ])->assertSee('Join');

    $component->call('dismiss')->assertDontSee("hasn't started yet");

    $newSession = GameSession::factory()->create(['type' => GameType::Farkle, 'mode' => GameMode::Online]);
    $room->update(['status' => RoomStatus::Active, 'game_session_id' => $newSession->id]);

    $component->call('onRoomChanged')->assertSee('The host started a new 10,000 game.');
});
