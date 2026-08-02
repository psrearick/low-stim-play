<?php

use App\Enums\GameMode;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Models\FamilyFeudQuestion;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function fakeFeudJudge(?int $matchIndex): void
{
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'choices' => [
                ['message' => ['content' => json_encode(['matchIndex' => $matchIndex === null ? null : $matchIndex + 1])]],
            ],
        ]),
    ]);
}

/**
 * Same two-step (game-lobby, then the game's own room page) drive as
 * YahtzeeRoomTest's own readyUpAndStart() -- see that file's docblock for
 * why Livewire::test() needs both steps separately rather than a bubbled
 * event. Generalized to N users (index 0 is always the host) since Family
 * Feud's team assignment needs more than 2 seats to exercise properly.
 *
 * @param  list<User>  $users
 * @return array<int, array{id: int, name: string, is_host: bool, ready: bool}>
 */
function readyUpFeudRoom(Room $room, array $users): array
{
    $component = Livewire::actingAs($users[0])->test('game-lobby', ['room' => $room]);

    foreach ($users as $i => $user) {
        $component->call('onJoining', ['id' => $user->id, 'name' => $user->name, 'is_host' => $i === 0]);
    }

    foreach ($users as $user) {
        $component->call('onReadyToggled', ['user_id' => $user->id, 'ready' => true]);
    }

    $component->call('startGame');

    return array_map(
        fn (User $user, int $i) => ['id' => $user->id, 'name' => $user->name, 'is_host' => $i === 0, 'ready' => true],
        $users,
        array_keys($users),
    );
}

/**
 * A plentiful question bank -- onRoomStarting() clamps $rounds to however
 * many questions exist (2 per round), so this keeps the default 5-round
 * setting intact rather than silently shrinking it.
 */
function seedFeudQuestions(int $count = 12): void
{
    FamilyFeudQuestion::factory()->count($count)->create();
}

test('the setup screen only shows the host button to a real account', function () {
    $guest = User::createGuest('Alex');

    $this->actingAs($guest)->get('/family-feud')->assertDontSee('Host an Online Room');

    $user = User::factory()->create();

    $this->actingAs($user)->get('/family-feud')->assertSee('Host an Online Room');
});

test('a guest cannot host a room', function () {
    $guest = User::createGuest('Alex');

    Livewire::actingAs($guest)
        ->test('pages::family-feud.play')
        ->call('createRoom')
        ->assertForbidden();

    expect(Room::count())->toBe(0);
});

test('joining with a valid code redirects to the family feud room', function () {
    $room = Room::factory()->create();
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::family-feud.play')
        ->set('joinID', strtolower($room->code))
        ->call('joinRoom')
        ->assertRedirect(route('family-feud.room', ['code' => $room->code]));
});

test('a guest can view the family feud room page without an account, and is given a throwaway one', function () {
    $room = Room::factory()->create();

    $this->get("/family-feud/room/{$room->code}")->assertOk();

    $guest = User::where('is_guest', true)->sole();

    expect($guest->is_guest)->toBeTrue()
        ->and(auth()->id())->toBe($guest->id);
});

test('starting the room assigns teams alternately by join order and marks each team\'s first seat as captain', function () {
    seedFeudQuestions();

    $users = User::factory()->count(4)->create();
    $room = Room::factory()->create(['host_user_id' => $users[0]->id]);

    $players = readyUpFeudRoom($room, $users->all());

    Livewire::actingAs($users[0])
        ->test('pages::family-feud.room', ['code' => $room->code])
        ->call('onRoomStarting', $players)
        ->assertRedirect(route('family-feud.play', ['room' => $room->code]));

    $session = GameSession::sole();

    expect($session->type)->toBe(GameType::FamilyFeud)
        ->and($session->mode)->toBe(GameMode::Online)
        ->and($session->state['teams'])->toHaveCount(2)
        ->and($session->state['controllingTeam'])->toBe(0)
        ->and($session->state['phase'])->toBe('play')
        ->and($room->fresh()->game_session_id)->toBe($session->id);

    // Alternating: seats 0/2 -> team 0, seats 1/3 -> team 1.
    expect($session->state['teamSeats'][0])->toHaveCount(2)
        ->and($session->state['teamSeats'][1])->toHaveCount(2)
        ->and($session->state['teamSeats'][0][0]['seatIndex'])->toBe(0)
        ->and($session->state['teamSeats'][0][0]['isCaptain'])->toBeTrue()
        ->and($session->state['teamSeats'][0][1]['isCaptain'])->toBeFalse()
        ->and($session->state['teamSeats'][1][0]['seatIndex'])->toBe(1)
        ->and($session->state['teamSeats'][1][0]['isCaptain'])->toBeTrue();

    expect(RoomSeat::count())->toBe(4)
        ->and(RoomSeat::where('room_id', $room->id)->where('user_id', $users[0]->id)->sole())
        ->toMatchArray(['team_index' => 0, 'is_captain' => true])
        ->and(RoomSeat::where('room_id', $room->id)->where('user_id', $users[2]->id)->sole())
        ->toMatchArray(['team_index' => 0, 'is_captain' => false])
        ->and(RoomSeat::where('room_id', $room->id)->where('user_id', $users[1]->id)->sole())
        ->toMatchArray(['team_index' => 1, 'is_captain' => true]);
});

test('starting the room twice does not create a second game session or duplicate seats', function () {
    seedFeudQuestions();

    $users = User::factory()->count(2)->create();
    $room = Room::factory()->create(['host_user_id' => $users[0]->id]);

    $players = readyUpFeudRoom($room, $users->all());

    $component = Livewire::actingAs($users[0])->test('pages::family-feud.room', ['code' => $room->code]);
    $component->call('onRoomStarting', $players);
    $component->call('onRoomStarting', $players);

    expect(GameSession::count())->toBe(1)
        ->and(RoomSeat::count())->toBe(2);
});

test('only the controlling team\'s current turn-pointer seat can submit a guess', function () {
    seedFeudQuestions();

    $users = User::factory()->count(2)->create();
    $room = Room::factory()->create(['host_user_id' => $users[0]->id]);

    $players = readyUpFeudRoom($room, $users->all());
    Livewire::actingAs($users[0])->test('pages::family-feud.room', ['code' => $room->code])->call('onRoomStarting', $players);

    fakeFeudJudge(null);

    // Seat 1 (team 1) is not up -- team 0 (seat 0) controls round 1.
    Livewire::actingAs($users[1])
        ->test('pages::family-feud.play', ['room' => $room->code])
        ->set('guessInput', 'anything')
        ->call('submitGuess')
        ->assertForbidden();

    Livewire::actingAs($users[0])
        ->test('pages::family-feud.play', ['room' => $room->code])
        ->set('guessInput', 'anything')
        ->call('submitGuess')
        ->assertSet('guessInput', '')
        ->assertSet('strikes', 1);
});

test('the within-team turn pointer rotates to the next teammate after each resolved guess', function () {
    seedFeudQuestions();

    $users = User::factory()->count(4)->create();
    $room = Room::factory()->create(['host_user_id' => $users[0]->id]);

    // Team 0 = seats 0 (host) and 2; team 1 = seats 1 and 3.
    $players = readyUpFeudRoom($room, $users->all());
    Livewire::actingAs($users[0])->test('pages::family-feud.room', ['code' => $room->code])->call('onRoomStarting', $players);

    fakeFeudJudge(null);

    // Seat 0 goes first for team 0.
    Livewire::actingAs($users[0])
        ->test('pages::family-feud.play', ['room' => $room->code])
        ->set('guessInput', 'first')
        ->call('submitGuess')
        ->assertSet('strikes', 1);

    // Pointer has moved on to seat 2 -- seat 0 (even though still on the
    // controlling team) is no longer the acting seat.
    Livewire::actingAs($users[0])
        ->test('pages::family-feud.play', ['room' => $room->code])
        ->set('guessInput', 'second')
        ->call('submitGuess')
        ->assertForbidden();

    Livewire::actingAs($users[2])
        ->test('pages::family-feud.play', ['room' => $room->code])
        ->set('guessInput', 'second')
        ->call('submitGuess')
        ->assertSet('strikes', 2);
});

test('only the stealing team\'s captain can act during Steal, not just any teammate', function () {
    seedFeudQuestions();

    $users = User::factory()->count(4)->create();
    $room = Room::factory()->create(['host_user_id' => $users[0]->id]);

    // Team 0 = seats 0 (captain), 2; team 1 = seats 1 (captain), 3.
    $players = readyUpFeudRoom($room, $users->all());
    Livewire::actingAs($users[0])->test('pages::family-feud.room', ['code' => $room->code])->call('onRoomStarting', $players);

    // Force straight to "team 0 struck out, team 1 may steal" rather than
    // playing three real misses through -- the authorization logic under
    // test only cares about phase/controllingTeam, not how it got there.
    $session = GameSession::sole();
    $state = $session->state;
    $state['phase'] = 'steal';
    $state['strikes'] = 3;
    $state['controllingTeam'] = 0;
    $session->update(['state' => $state]);

    fakeFeudJudge(null);

    // Seat 3 is on the stealing team (team 1) but isn't its captain.
    Livewire::actingAs($users[3])
        ->test('pages::family-feud.play', ['room' => $room->code])
        ->set('guessInput', 'anything')
        ->call('submitGuess')
        ->assertForbidden();

    // The controlling (non-stealing) team's own captain has no say either.
    Livewire::actingAs($users[0])
        ->test('pages::family-feud.play', ['room' => $room->code])
        ->set('guessInput', 'anything')
        ->call('submitGuess')
        ->assertForbidden();

    Livewire::actingAs($users[1])
        ->test('pages::family-feud.play', ['room' => $room->code])
        ->set('guessInput', 'anything')
        ->call('submitGuess')
        ->assertSet('guessInput', '');
});

test('an unseated spectator sees no seat and cannot mutate the game', function () {
    seedFeudQuestions();

    $users = User::factory()->count(2)->create();
    $spectator = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $users[0]->id]);

    $players = readyUpFeudRoom($room, $users->all());
    Livewire::actingAs($users[0])->test('pages::family-feud.room', ['code' => $room->code])->call('onRoomStarting', $players);

    Livewire::actingAs($spectator)
        ->test('pages::family-feud.play', ['room' => $room->code])
        ->assertSet('mySeatIndex', null)
        ->set('guessInput', 'anything')
        ->call('submitGuess')
        ->assertForbidden();
});

test('reloading mid-game rehydrates the correct seat/team and in-progress state instead of resetting to setup', function () {
    seedFeudQuestions();

    $users = User::factory()->count(2)->create();
    $room = Room::factory()->create(['host_user_id' => $users[0]->id]);

    $players = readyUpFeudRoom($room, $users->all());
    Livewire::actingAs($users[0])->test('pages::family-feud.room', ['code' => $room->code])->call('onRoomStarting', $players);

    fakeFeudJudge(null);

    Livewire::actingAs($users[0])
        ->test('pages::family-feud.play', ['room' => $room->code])
        ->set('guessInput', 'nope')
        ->call('submitGuess');

    // A brand-new component instance, as a fresh page load/reconnect would
    // be -- rehydrated entirely from the persisted GameSession.state.
    Livewire::actingAs($users[1])
        ->test('pages::family-feud.play', ['room' => $room->code])
        ->assertSet('mySeatIndex', 1)
        ->assertSet('myTeamIndex', 1)
        ->assertSet('started', true)
        ->assertSet('strikes', 1);
});

test('visiting the room page after the game already started redirects straight into it, not the stale lobby', function () {
    seedFeudQuestions();

    $users = User::factory()->count(2)->create();
    $room = Room::factory()->create(['host_user_id' => $users[0]->id]);

    $players = readyUpFeudRoom($room, $users->all());
    Livewire::actingAs($users[0])->test('pages::family-feud.room', ['code' => $room->code])->call('onRoomStarting', $players);

    Livewire::actingAs($users[1])
        ->test('pages::family-feud.room', ['code' => $room->code])
        ->assertRedirect(route('family-feud.play', ['room' => $room->code]));
});

test('a full online game writes one correctly-attributed GameParticipant per seat', function () {
    seedFeudQuestions();

    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $players = readyUpFeudRoom($room, [$host, $guest]);
    Livewire::actingAs($host)->test('pages::family-feud.room', ['code' => $room->code])->call('onRoomStarting', $players);

    Livewire::actingAs($host)
        ->test('pages::family-feud.play', ['room' => $room->code])
        ->set('teams', [
            ['name' => 'Team 1', 'score' => 120],
            ['name' => 'Team 2', 'score' => 80],
        ])
        ->call('endGame');

    $session = GameSession::sole();

    expect($session->ended_at)->not->toBeNull();

    expect(GameParticipant::count())->toBe(2);

    $hostParticipant = GameParticipant::where('user_id', $host->id)->sole();
    $guestParticipant = GameParticipant::where('user_id', $guest->id)->sole();

    expect($hostParticipant->guest_name)->toBeNull()
        ->and($hostParticipant->score)->toBe(120)
        ->and($hostParticipant->result)->toBe(GameResult::Win)
        ->and($hostParticipant->stats['team_index'])->toBe(0)
        ->and($hostParticipant->stats['is_captain'])->toBeTrue()
        ->and($guestParticipant->score)->toBe(80)
        ->and($guestParticipant->result)->toBe(GameResult::Loss)
        ->and($guestParticipant->stats['team_index'])->toBe(1)
        ->and($guestParticipant->stats['is_captain'])->toBeTrue();
});
