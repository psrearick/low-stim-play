<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Events\RoomGameFinished;
use App\Events\RoomGameSwitched;
use App\Events\RoomPlayerReadyToggled;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The between-games flow (docs/DEVLOG.md's Room system section, "Game
 * transitions"): what a room looks like after a game ends, and every way
 * into the next one.
 */

/**
 * @return array{0: User, 1: User, 2: Room, 3: GameSession}
 */
function finishedFarkleRoom(): array
{
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $room->addMember($guest);

    // The lobby that started this game, then the game itself.
    $room->openLobby(GameType::Farkle, ['targetScore' => 15000, 'withFinalRound' => false, 'spicyEnabled' => false, 'highStakesEnabled' => true]);
    $session = GameSession::factory()->create(['type' => GameType::Farkle, 'mode' => GameMode::Online]);
    $room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);

    $room->finishCurrentGame($session->id);

    return [$host, $guest, $room->fresh(), $session];
}

test('finishing a game puts the room back in the lobby, clears the stale pending marker, and broadcasts', function () {
    Event::fake([RoomGameFinished::class, RoomGameSwitched::class]);

    [, , $room, $session] = finishedFarkleRoom();

    expect($room->status)->toBe(RoomStatus::Lobby)
        ->and($room->game_session_id)->toBe($session->id)
        ->and($room->pendingGameType())->toBeNull();

    Event::assertDispatched(RoomGameFinished::class, fn (RoomGameFinished $event) => $event->room->is($room));
});

test('a straggler finishing an old session never wipes out the next lobby', function () {
    [, , $room, $session] = finishedFarkleRoom();
    $room->openLobby(GameType::Hoax);

    Event::fake([RoomGameFinished::class]);
    $room->finishCurrentGame($session->id);

    expect($room->pendingGameType())->toBe(GameType::Hoax);
    Event::assertNotDispatched(RoomGameFinished::class);
});

test('opening a lobby that is already open saves settings without re-notifying anyone', function () {
    $room = Room::factory()->create();

    Event::fake([RoomGameSwitched::class]);
    $room->openLobby(GameType::Farkle, ['targetScore' => 5000]);
    $token = $room->pendingGameToken();
    $room->openLobby(GameType::Farkle, ['targetScore' => 7500]);

    Event::assertDispatchedTimes(RoomGameSwitched::class, 1);
    expect($room->pendingGameToken())->toBe($token)
        ->and($room->rememberedSettings(GameType::Farkle))->toBe(['targetScore' => 7500]);
});

test('each new lobby opening gets a fresh follow token', function () {
    $room = Room::factory()->create();

    $room->markPendingGame(GameType::Farkle);
    $first = $room->pendingGameToken();
    $room->markPendingGame(GameType::Farkle);

    expect($room->pendingGameToken())->not->toBe($first)->not->toBeNull();
});

test('after a game ends the host lands on the config screen with last round\'s settings, not a stale open lobby', function () {
    [$host, , $room] = finishedFarkleRoom();

    Livewire::actingAs($host)
        ->test('pages::farkle.room', ['code' => $room->code])
        ->assertSet('lobbyOpened', false)
        ->assertSet('targetScore', 15000)
        ->assertSet('withFinalRound', false)
        ->assertSet('highStakesEnabled', true)
        ->assertSee('Open Lobby');
});

test('the room hub shows no stale "setting up" banner after a game ends', function () {
    [$host, $guest, $room] = finishedFarkleRoom();

    Livewire::actingAs($host)->test('pages::room.show', ['code' => $room->code])
        ->assertDontSee('Continue Setup');
    Livewire::actingAs($guest)->test('pages::room.show', ['code' => $room->code])
        ->assertDontSee('setting up');
});

test('the host opening a different game from its own page sees that game\'s config, not "the host switched"', function () {
    [$host, , $room] = finishedFarkleRoom();
    $room->openLobby(GameType::Boggle);

    Livewire::actingAs($host)
        ->test('pages::hoax.room', ['code' => $room->code])
        ->assertDontSee('The host switched to')
        ->assertSee('Open Lobby');
});

test('a non-host on an old lobby still gets the follow countdown when the host switches games', function () {
    [, $guest, $room] = finishedFarkleRoom();
    $room->openLobby(GameType::Hoax);

    Livewire::actingAs($guest)
        ->test('pages::farkle.room', ['code' => $room->code])
        ->assertSee('The host switched to Hoax.')
        ->assertSee('Stay here')
        ->assertSeeHtml(route('hoax.room', ['code' => $room->code]));
});

test('the host can go back to settings from an open lobby and reconfirm them without re-notifying', function () {
    [$host, , $room] = finishedFarkleRoom();
    $room->openLobby(GameType::Farkle);

    Event::fake([RoomGameSwitched::class]);

    Livewire::actingAs($host)
        ->test('pages::farkle.room', ['code' => $room->code])
        ->assertSet('lobbyOpened', true)
        ->assertSee('Change settings')
        ->call('editSettings')
        ->assertSet('lobbyOpened', false)
        ->call('$set', 'targetScore', 5000)
        ->call('openLobby')
        ->assertSet('lobbyOpened', true);

    Event::assertNotDispatched(RoomGameSwitched::class);
    expect($room->rememberedSettings(GameType::Farkle)['targetScore'])->toBe(5000);
});

test('only the host can go back to settings', function () {
    [, $guest, $room] = finishedFarkleRoom();
    $room->openLobby(GameType::Farkle);

    Livewire::actingAs($guest)
        ->test('pages::farkle.room', ['code' => $room->code])
        ->assertDontSee('Change settings')
        ->call('editSettings')
        ->assertForbidden();
});

test('the host\'s results screen offers Play again and Choose another game', function () {
    [$host, , $room, $session] = finishedFarkleRoom();

    Livewire::actingAs($host)
        ->test('room-new-game-notice', ['roomCode' => $room->code, 'currentGame' => GameType::Farkle, 'myGameSessionId' => $session->id])
        ->assertSee("Game over -- what's next?")
        ->assertSee('Play again')
        ->assertSee('Choose another game')
        ->assertSeeHtml(route('room.show', ['code' => $room->code]));
});

test('a non-host results screen says the host is choosing', function () {
    [, $guest, $room, $session] = finishedFarkleRoom();

    Livewire::actingAs($guest)
        ->test('room-new-game-notice', ['roomCode' => $room->code, 'currentGame' => GameType::Farkle, 'myGameSessionId' => $session->id])
        ->assertSee('Waiting for the host to pick the next game')
        ->assertDontSee('Play again');
});

test('the results notice switches to "what\'s next" live when the game-finished broadcast arrives', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $session = GameSession::factory()->create(['type' => GameType::Farkle, 'mode' => GameMode::Online]);
    $room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);

    $notice = Livewire::actingAs($host)
        ->test('room-new-game-notice', ['roomCode' => $room->code, 'currentGame' => GameType::Farkle, 'myGameSessionId' => $session->id])
        ->assertDontSee('Play again');

    $room->finishCurrentGame($session->id);

    $notice->call('onRoomChanged')->assertSee('Play again');
});

test('Play again reopens the same game\'s lobby with the remembered settings and notifies everyone', function () {
    [$host, , $room, $session] = finishedFarkleRoom();

    Event::fake([RoomGameSwitched::class]);

    Livewire::actingAs($host)
        ->test('room-new-game-notice', ['roomCode' => $room->code, 'currentGame' => GameType::Farkle, 'myGameSessionId' => $session->id])
        ->call('playAgain')
        ->assertRedirect(route('farkle.room', ['code' => $room->code]));

    Event::assertDispatched(RoomGameSwitched::class, fn (RoomGameSwitched $event) => $event->type === GameType::Farkle);

    Livewire::actingAs($host)
        ->test('pages::farkle.room', ['code' => $room->code])
        ->assertSet('lobbyOpened', true)
        ->assertSet('targetScore', 15000);
});

test('only the host can Play again', function () {
    [, $guest, $room, $session] = finishedFarkleRoom();

    Livewire::actingAs($guest)
        ->test('room-new-game-notice', ['roomCode' => $room->code, 'currentGame' => GameType::Farkle, 'myGameSessionId' => $session->id])
        ->call('playAgain')
        ->assertForbidden();
});

test('a finished game\'s results screen auto-follows the next lobby, but never one still in progress', function () {
    [, $guest, $room, $session] = finishedFarkleRoom();
    $room->openLobby(GameType::Hoax);

    Livewire::actingAs($guest)
        ->test('room-new-game-notice', ['roomCode' => $room->code, 'currentGame' => GameType::Farkle, 'myGameSessionId' => $session->id])
        ->assertSee("The host is setting up Hoax -- hasn't started yet.")
        ->assertSee('Stay here');

    // This page's game still being played -- nothing pulls the player away.
    $room->update(['status' => RoomStatus::Active]);

    Livewire::actingAs($guest)
        ->test('room-new-game-notice', ['roomCode' => $room->code, 'currentGame' => GameType::Farkle, 'myGameSessionId' => $session->id])
        ->assertDontSee('Stay here');
});

test('the room hub gives a non-host the follow countdown for a pending lobby', function () {
    [, $guest, $room] = finishedFarkleRoom();
    $room->openLobby(GameType::Hoax);

    Livewire::actingAs($guest)
        ->test('pages::room.show', ['code' => $room->code])
        ->assertSee('The host is setting up Hoax')
        ->assertSee('Stay here')
        ->assertSeeHtml('room-follow-stay:'.$room->pendingGameToken());
});

test('the room hub refreshes when a game starts, dropping the stale Join', function () {
    [, $guest, $room] = finishedFarkleRoom();
    $room->openLobby(GameType::Hoax);

    $hub = Livewire::actingAs($guest)
        ->test('pages::room.show', ['code' => $room->code])
        ->assertSee('The host is setting up Hoax');

    $session = GameSession::factory()->create(['type' => GameType::Hoax, 'mode' => GameMode::Online]);
    $room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);

    $hub->call('onRoomChanged')
        ->assertDontSee('The host is setting up Hoax')
        ->assertSee('Playing Hoax right now.');
});

test('arriving at a Word Grid room mid-game goes to the room hub instead of a dead-end waiting screen', function () {
    [, $guest, $room] = finishedFarkleRoom();
    $session = GameSession::factory()->create(['type' => GameType::Boggle, 'mode' => GameMode::Online, 'settings' => ['size' => 4, 'seed' => 1, 'minutes' => 3]]);
    $room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);

    Livewire::actingAs($guest)
        ->test('pages::boggle.room', ['code' => $room->code])
        ->assertRedirect(route('room.show', ['code' => $room->code]));
});

test('reloading a Word Grid results screen after the room moved on redirects instead of crashing', function () {
    [, $guest, $room] = finishedFarkleRoom();
    $room->update(['game_session_id' => null]);

    Livewire::actingAs($guest)
        ->withQueryParams(['room' => $room->code])
        ->test('pages::boggle.play')
        ->assertRedirect(route('room.show', ['code' => $room->code]));

    $session = GameSession::factory()->create(['type' => GameType::Farkle, 'mode' => GameMode::Online]);
    $room->update(['game_session_id' => $session->id]);

    Livewire::actingAs($guest)
        ->withQueryParams(['room' => $room->code])
        ->test('pages::boggle.play')
        ->assertRedirect(route('room.show', ['code' => $room->code]));
});

test('lobby presence and ready toggles use the lobby-only channel, not the site-wide room channel', function () {
    $room = Room::factory()->create();

    $lobby = Livewire::test('game-lobby', ['room' => $room, 'currentGame' => GameType::Farkle])->instance();
    $listeners = (fn () => $this->getListeners())->call($lobby);

    expect($listeners)->toHaveKey("echo-presence:room.{$room->code}.lobby,here")
        ->toHaveKey("echo-presence:room.{$room->code}.lobby,RoomPlayerReadyToggled")
        ->not->toHaveKey("echo-presence:room.{$room->code},here");

    $channel = (new RoomPlayerReadyToggled($room, 1, true))->broadcastOn()[0];
    expect($channel->name)->toBe("presence-room.{$room->code}.lobby");
});

test('members who are not in the lobby are listed but never block Start Game', function () {
    $host = User::factory()->create();
    $idle = User::factory()->create(['name' => 'Idle Ida']);
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $room->addMember($idle);

    Livewire::actingAs($host)
        ->test('game-lobby', ['room' => $room, 'currentGame' => GameType::Farkle])
        ->call('onHere', [['id' => $host->id, 'name' => $host->name, 'is_host' => true]])
        ->call('toggleReady')
        ->assertSee('Idle Ida')
        ->assertSee('Not in lobby')
        ->assertSet('allReady', true);
});

test('the navbar skips toasts for the room whose own page is already open', function () {
    [, $guest, $room] = finishedFarkleRoom();

    // Elsewhere first -- withQueryParams() below stays set for the rest of
    // the test.
    $elsewhere = Livewire::actingAs($guest)
        ->test('rooms-menu')
        ->call('onGameSwitched', ['type' => GameType::Hoax->value, 'code' => $room->code]);

    expect($elsewhere->get('notifications'))->toHaveCount(1);

    $onRoomPage = Livewire::actingAs($guest)
        ->withQueryParams(['room' => $room->code])
        ->test('rooms-menu')
        ->call('onGameSwitched', ['type' => GameType::Hoax->value, 'code' => $room->code]);

    expect($onRoomPage->get('notifications'))->toBe([]);
});
