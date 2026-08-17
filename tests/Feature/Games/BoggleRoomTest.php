<?php

use App\Enums\GameMode;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Events\RoomGameEnded;
use App\Events\RoomGameStarting;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the lobby shows no room UI at all for a user hosting no rooms', function () {
    // "Join a Room"/"Host an Online Room" no longer exist here at all --
    // hosting/joining now only happens through the global navbar
    // rooms-menu (see tests/Feature/RoomsMenuTest.php), unrelated to
    // whether *this* lobby has anything room-related of its own to show.
    $user = User::factory()->create();

    $this->actingAs($user)->get('/word-grid')
        ->assertDontSee('Play in a room you host');

    $this->get('/word-grid')->assertDontSee('Play in a room you host');
});

test('the lobby shows a dropdown of rooms the user hosts, linking into each one\'s room page', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $this->actingAs($host)->get('/word-grid')
        ->assertSee('Play in a room you host')
        ->assertSee($room->code)
        ->assertSeeHtml(route('boggle.room', ['code' => $room->code]));
});

test('a guest can view a room page without an account, and is given a throwaway one', function () {
    $room = Room::factory()->create();

    $this->get("/word-grid/room/{$room->code}")->assertOk();

    $guest = User::where('is_guest', true)->sole();

    expect($guest->is_guest)->toBeTrue()
        ->and($guest->name)->toBe('Guest')
        ->and(auth()->id())->toBe($guest->id);
});

test('an unknown room code 404s', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/word-grid/room/ZZZZZZ')->assertNotFound();
});

test('only the host sees the board size and time limit controls', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $this->actingAs($host)->get("/word-grid/room/{$room->code}")->assertSee('Board Size');
    $this->actingAs($guest)->get("/word-grid/room/{$room->code}")->assertDontSee('Board Size');
});

test('starting the room creates a shared game session and redirects the host into it', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    Livewire::actingAs($host)
        ->test('pages::boggle.room', ['code' => $room->code])
        ->set('size', 5)
        ->set('minutes', 2)
        ->call('onRoomStarting')
        ->assertRedirect(route('boggle.play', [
            'size' => 5,
            'minutes' => 2,
            'seed' => $room->fresh()->gameSession->settings['seed'],
            'room' => $room->code,
        ]));

    $session = GameSession::sole();

    expect($session->type)->toBe(GameType::Boggle)
        ->and($session->mode)->toBe(GameMode::Online)
        ->and($session->settings['size'])->toBe(5)
        ->and($session->settings['minutes'])->toBe(2)
        ->and($room->fresh()->game_session_id)->toBe($session->id);
});

test('RoomGameStarting is only broadcast after game_session_id is actually set', function () {
    // Regression test: this event used to be broadcast from
    // GameLobby::startGame(), *before* this component's own separate
    // request had created the GameSession and set game_session_id --
    // fine when broadcasting was queued (a real delay masked the race),
    // but once it became ShouldBroadcastNow (near-instant), another
    // player could receive it, check game_session_id, see null, and never
    // get redirected into the game at all.
    Event::fake([RoomGameStarting::class]);

    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    Livewire::actingAs($host)
        ->test('pages::boggle.room', ['code' => $room->code])
        ->call('onRoomStarting');

    Event::assertDispatched(
        RoomGameStarting::class,
        fn (RoomGameStarting $event) => $event->room->game_session_id !== null,
    );
});

test('starting the room twice does not create a second game session', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $component = Livewire::actingAs($host)->test('pages::boggle.room', ['code' => $room->code]);
    $component->call('onRoomStarting');
    $component->call('onRoomStarting');

    expect(GameSession::count())->toBe(1);
});

test('replaying a room after a previous game generates a fresh board and notifies both players', function () {
    // Regression test: game_session_id used to be set once and never
    // cleared, so a room's second game found it already non-null, skipped
    // generating a new board/session for the host (reusing the prior
    // game's board), and -- since RoomGameStarting is only ever dispatched
    // from inside that same "it's null" branch -- never broadcast anything
    // at all, stranding the guest in the lobby.
    Event::fake([RoomGameStarting::class]);

    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id, 'status' => RoomStatus::Active]);

    $previousSession = GameSession::factory()->create([
        'type' => GameType::Boggle,
        'mode' => GameMode::Online,
        'settings' => ['size' => 4, 'seed' => 1, 'minutes' => 3],
        'ended_at' => now(),
    ]);
    $room->update(['game_session_id' => $previousSession->id]);

    // GameLobby::startGame() is what actually resets game_session_id back
    // to null before handing off -- reproduce that here rather than reset
    // it directly, since that reset is the fix under test.
    Livewire::actingAs($host)
        ->test('game-lobby', ['room' => $room, 'currentGame' => GameType::Boggle])
        ->call('onJoining', ['id' => $host->id, 'name' => $host->name, 'is_host' => true])
        ->call('onJoining', ['id' => $guest->id, 'name' => $guest->name, 'is_host' => false])
        ->call('onReadyToggled', ['user_id' => $host->id, 'ready' => true])
        ->call('onReadyToggled', ['user_id' => $guest->id, 'ready' => true])
        ->call('startGame');

    expect($room->fresh()->game_session_id)->toBeNull();

    Livewire::actingAs($host)
        ->test('pages::boggle.room', ['code' => $room->code])
        ->call('onRoomStarting');

    $newSession = $room->fresh()->gameSession;

    expect(GameSession::count())->toBe(2)
        ->and($newSession->id)->not->toBe($previousSession->id);

    Event::assertDispatched(
        RoomGameStarting::class,
        fn (RoomGameStarting $event) => $event->room->game_session_id === $newSession->id,
    );
});

test('other players are redirected once the room has started', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    // In production, GameLobby::startGame() (a separate component) always
    // flips the room to Active *before* dispatching the event that leads
    // here, in the same request. Reproduce that ordering directly since
    // this test exercises pages::boggle.room in isolation.
    $room->update(['status' => RoomStatus::Active]);

    Livewire::actingAs($host)
        ->test('pages::boggle.room', ['code' => $room->code])
        ->call('onRoomStarting');

    Livewire::actingAs($guest)
        ->test('pages::boggle.room', ['code' => $room->code])
        ->call('onRoomStarted')
        ->assertRedirect(route('boggle.play', [
            'size' => $room->fresh()->gameSession->settings['size'],
            'minutes' => $room->fresh()->gameSession->settings['minutes'],
            'seed' => $room->fresh()->gameSession->settings['seed'],
            'room' => $room->code,
        ]));
});

test('players in a room play the same board and are ranked against each other', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    Livewire::actingAs($host)
        ->test('pages::boggle.room', ['code' => $room->code])
        ->set('size', 4)
        ->set('minutes', 3)
        ->call('onRoomStarting');

    $session = GameSession::sole();
    $seed = $session->settings['seed'];

    $hostBoard = Livewire::actingAs($host)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'seed' => $seed, 'room' => $room->code])
        ->get('board');

    $guestGame = Livewire::actingAs($guest)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'seed' => $seed, 'room' => $room->code]);

    expect($guestGame->get('board'))->toBe($hostBoard);

    Livewire::actingAs($host)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'seed' => $seed, 'room' => $room->code])
        ->set('board', $hostBoard)
        ->set('foundWords', ['cat' => 4, 'dog' => 6])
        ->set('score', 10)
        ->call('endGame');

    Livewire::actingAs($guest)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'seed' => $seed, 'room' => $room->code])
        ->set('board', $hostBoard)
        ->set('foundWords', ['rat' => 3])
        ->set('score', 3)
        ->call('endGame');

    expect(GameSession::count())->toBe(1)
        ->and(GameParticipant::count())->toBe(2);

    $hostParticipant = GameParticipant::where('user_id', $host->id)->sole();
    $guestParticipant = GameParticipant::where('user_id', $guest->id)->sole();

    expect($hostParticipant->result)->toBe(GameResult::Win)
        ->and($guestParticipant->result)->toBe(GameResult::Loss);

    // docs/concerns.md: "Back to room button does not do anything on
    // game over screen." Root cause was that Room.status/game_session_id
    // never changed once a game ended, so pages::boggle.room's own
    // redirectIfAlreadyStarted() kept bouncing straight back into the
    // now-finished game. Confirm both halves of the fix.
    expect($room->fresh()->status)->toBe(RoomStatus::Finished);

    Livewire::actingAs($host)
        ->test('pages::boggle.room', ['code' => $room->code])
        ->assertOk()
        ->assertSee('Word Grid Room');
});

test('a word both players found scores nothing for either of them', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $session = GameSession::factory()->create([
        'type' => GameType::Boggle,
        'mode' => GameMode::Online,
        'settings' => ['size' => 4, 'seed' => 1, 'minutes' => 3],
    ]);
    $room->update(['game_session_id' => $session->id]);

    // Both found "cat" (worth 1) -- shared, so it counts for neither.
    // The host's "dog" (worth 1) and the guest's "rat" (worth 1) are each
    // exclusive to that player, so those still count.
    Livewire::actingAs($host)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'seed' => 1, 'room' => $room->code])
        ->set('foundWords', ['cat' => 1, 'dog' => 1])
        ->set('score', 2)
        ->call('endGame');

    Livewire::actingAs($guest)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'seed' => 1, 'room' => $room->code])
        ->set('foundWords', ['cat' => 1, 'rat' => 1])
        ->set('score', 2)
        ->call('endGame');

    $hostParticipant = GameParticipant::where('user_id', $host->id)->sole();
    $guestParticipant = GameParticipant::where('user_id', $guest->id)->sole();

    expect($hostParticipant->score)->toBe(1)
        ->and($guestParticipant->score)->toBe(1)
        ->and($hostParticipant->result)->toBe(GameResult::Draw)
        ->and($guestParticipant->result)->toBe(GameResult::Draw);
});

test('a tie for the top score is a draw for everyone tied', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $session = GameSession::factory()->create([
        'type' => GameType::Boggle,
        'mode' => GameMode::Online,
        'settings' => ['size' => 4, 'seed' => 1, 'minutes' => 3],
    ]);
    $room->update(['game_session_id' => $session->id]);

    Livewire::actingAs($host)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'seed' => 1, 'room' => $room->code])
        ->set('score', 5)
        ->call('endGame');

    Livewire::actingAs($guest)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'seed' => 1, 'room' => $room->code])
        ->set('score', 5)
        ->call('endGame');

    expect(GameParticipant::pluck('result')->unique()->all())->toBe([GameResult::Draw]);
});

test('ending the game broadcasts to the room, and receiving that broadcast ends it for another player too', function () {
    Event::fake([RoomGameEnded::class]);

    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $session = GameSession::factory()->create([
        'type' => GameType::Boggle,
        'mode' => GameMode::Online,
        'settings' => ['size' => 4, 'seed' => 1, 'minutes' => 3],
    ]);
    $room->update(['game_session_id' => $session->id]);

    Livewire::actingAs($host)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'seed' => 1, 'room' => $room->code])
        ->call('endGame')
        ->assertSet('ended', true);

    Event::assertDispatched(
        RoomGameEnded::class,
        fn (RoomGameEnded $event) => $event->roomCode === $room->code,
    );

    // The guest's own game is still running until they receive the
    // broadcast above -- onRoomGameEnded() is what a real page's Echo
    // listener calls when that arrives, ending their game too.
    Event::fake([RoomGameEnded::class]);

    Livewire::actingAs($guest)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'seed' => 1, 'room' => $room->code])
        ->assertSet('ended', false)
        ->call('onRoomGameEnded')
        ->assertSet('ended', true);

    // The guest re-broadcasts on finishing too -- finalizeSharedSession()
    // just recalculated every participant's score using the now-complete
    // participant set, and the host (who finished first, before the guest's
    // words were known) needs this to refresh their own possibly-changed
    // score. See onRoomGameEnded()'s docblock.
    Event::assertDispatched(
        RoomGameEnded::class,
        fn (RoomGameEnded $event) => $event->roomCode === $room->code,
    );
});

test('a player who finished first gets their score refreshed once the other player finishes and changes the shared-word tally', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $session = GameSession::factory()->create([
        'type' => GameType::Boggle,
        'mode' => GameMode::Online,
        'settings' => ['size' => 4, 'seed' => 1, 'minutes' => 3],
    ]);
    $room->update(['game_session_id' => $session->id]);

    // Host finishes first, with no one else having finished yet -- every
    // word they found looks "exclusive" at this point, since finderCounts
    // is only ever built from participants who have already finished.
    Livewire::actingAs($host)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'seed' => 1, 'room' => $room->code])
        ->set('foundWords', ['cat' => 1, 'dog' => 1])
        ->set('score', 2)
        ->call('endGame')
        ->assertSet('score', 2);

    // Guest finishes later, having also found "cat" -- now that it's known
    // to be shared, finalizeSharedSession() drops it from both scores.
    Livewire::actingAs($guest)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'seed' => 1, 'room' => $room->code])
        ->set('foundWords', ['cat' => 1, 'run' => 1])
        ->set('score', 2)
        ->call('endGame')
        ->assertSet('score', 1);

    expect(GameParticipant::where('user_id', $host->id)->sole()->score)->toBe(1);

    // The host's own component instance should reflect that corrected
    // score too, once it receives the guest's cascading broadcast -- not
    // the stale value of 2 it computed before the guest had finished.
    Livewire::actingAs($host)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'seed' => 1, 'room' => $room->code])
        ->set('gameSessionId', $session->id)
        ->set('ended', true)
        ->set('score', 2)
        ->call('onRoomGameEnded')
        ->assertSet('score', 1);
});

test('resetting the game is a no-op inside a room', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $session = GameSession::factory()->create([
        'type' => GameType::Boggle,
        'mode' => GameMode::Online,
        'settings' => ['size' => 4, 'seed' => 1, 'minutes' => 3],
    ]);
    $room->update(['game_session_id' => $session->id]);

    Livewire::actingAs($host)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'seed' => 1, 'room' => $room->code])
        ->call('endGame')
        ->assertSet('ended', true)
        ->call('resetGame')
        ->assertSet('ended', true);
});
