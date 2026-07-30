<?php

use App\Enums\GameMode;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Events\RoomGameEnded;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are prompted to sign in to host, but can still see the join form', function () {
    $this->get('/boggle')
        ->assertSee('Sign in')
        ->assertDontSee('Host an Online Room')
        ->assertSee('Room code');
});

test('authenticated users can create a room and are redirected into it', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::boggle.lobby')
        ->call('createRoom')
        ->assertRedirect();

    $room = Room::sole();

    expect($room->host_user_id)->toBe($user->id)
        ->and($room->status)->toBe(RoomStatus::Lobby);
});

test('joining with a valid code redirects to the room', function () {
    $room = Room::factory()->create();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::boggle.lobby')
        ->set('joinCode', strtolower($room->code))
        ->call('joinRoom')
        ->assertRedirect(route('boggle.room', ['code' => $room->code]));
});

test('joining with an invalid code shows an error instead of redirecting', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::boggle.lobby')
        ->set('joinCode', 'NOPE99')
        ->call('joinRoom')
        ->assertNoRedirect()
        ->assertSet('joinError', 'No active room with that code.');
});

test('a guest can view a room page without an account, and is given a throwaway one', function () {
    $room = Room::factory()->create();

    $this->get("/boggle/room/{$room->code}")->assertOk();

    $guest = User::where('is_guest', true)->sole();

    expect($guest->is_guest)->toBeTrue()
        ->and($guest->name)->toBe('Guest')
        ->and(auth()->id())->toBe($guest->id);
});

test('joining a room from the lobby without an account creates a guest and uses the chosen name', function () {
    $room = Room::factory()->create();

    Livewire::test('pages::boggle.lobby')
        ->set('guestName', 'Alex')
        ->set('joinCode', $room->code)
        ->call('joinRoom')
        ->assertRedirect(route('boggle.room', ['code' => $room->code]));

    $guest = User::where('is_guest', true)->sole();

    expect($guest->is_guest)->toBeTrue()
        ->and($guest->name)->toBe('Alex');
});

test('a guest cannot host a room', function () {
    $guest = User::createGuest('Alex');

    Livewire::actingAs($guest)
        ->test('pages::boggle.lobby')
        ->call('createRoom')
        ->assertForbidden();

    expect(Room::count())->toBe(0);
});

test('the lobby only shows the host button to a real account', function () {
    $guest = User::createGuest('Alex');

    $this->actingAs($guest)->get('/boggle')->assertDontSee('Host an Online Room');

    $user = User::factory()->create();

    $this->actingAs($user)->get('/boggle')->assertSee('Host an Online Room');
});

test('an unknown room code 404s', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/boggle/room/ZZZZZZ')->assertNotFound();
});

test('only the host sees the board size and time limit controls', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $this->actingAs($host)->get("/boggle/room/{$room->code}")->assertSee('Board Size');
    $this->actingAs($guest)->get("/boggle/room/{$room->code}")->assertDontSee('Board Size');
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

test('starting the room twice does not create a second game session', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $component = Livewire::actingAs($host)->test('pages::boggle.room', ['code' => $room->code]);
    $component->call('onRoomStarting');
    $component->call('onRoomStarting');

    expect(GameSession::count())->toBe(1);
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
    // listener calls when that arrives, ending their game too without
    // re-announcing it (see the count() assertion below).
    Event::fake([RoomGameEnded::class]);

    Livewire::actingAs($guest)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'seed' => 1, 'room' => $room->code])
        ->assertSet('ended', false)
        ->call('onRoomGameEnded')
        ->assertSet('ended', true);

    Event::assertNotDispatched(RoomGameEnded::class);
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
