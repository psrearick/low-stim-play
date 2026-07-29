<?php

use App\Enums\GameMode;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are prompted to sign in instead of seeing room controls', function () {
    $this->get('/boggle')
        ->assertSee('Sign in')
        ->assertDontSee('Host an online room');
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

test('guests are redirected away from a room page', function () {
    $room = Room::factory()->create();

    $this->get("/boggle/room/{$room->code}")->assertRedirect('/login');
});

test('an unknown room code 404s', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/boggle/room/ZZZZZZ')->assertNotFound();
});

test('only the host sees the board size and time limit controls', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $this->actingAs($host)->get("/boggle/room/{$room->code}")->assertSee('Board size');
    $this->actingAs($guest)->get("/boggle/room/{$room->code}")->assertDontSee('Board size');
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
        ->set('score', 10)
        ->call('endGame');

    Livewire::actingAs($guest)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 3, 'seed' => $seed, 'room' => $room->code])
        ->set('board', $hostBoard)
        ->set('score', 3)
        ->call('endGame');

    expect(GameSession::count())->toBe(1)
        ->and(GameParticipant::count())->toBe(2);

    $hostParticipant = GameParticipant::where('user_id', $host->id)->sole();
    $guestParticipant = GameParticipant::where('user_id', $guest->id)->sole();

    expect($hostParticipant->result)->toBe(GameResult::Win)
        ->and($guestParticipant->result)->toBe(GameResult::Loss);
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
