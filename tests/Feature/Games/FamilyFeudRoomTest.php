<?php

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
use Livewire\Features\SupportTesting\Testable;
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
    $component = Livewire::actingAs($users[0])->test('game-lobby', ['room' => $room, 'currentGame' => GameType::FamilyFeud]);

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

/**
 * Drives the host all the way from the lobby through the team-assignment
 * screen to an actual started round, accepting whatever the default
 * alternating team/captain assignment lands on -- for tests that only
 * care about what happens once a round is underway, not about the
 * assignment screen itself (that gets its own dedicated tests below).
 *
 * @param  list<User>  $users
 * @return array{0: Testable, 1: array<int, array{id: int, name: string, is_host: bool, ready: bool}>}
 */
function startFeudRoomWithDefaultTeams(Room $room, array $users): array
{
    $players = readyUpFeudRoom($room, $users);

    $component = Livewire::actingAs($users[0])
        ->test('pages::family-feud.room', ['code' => $room->code])
        ->call('onRoomStarting', $players)
        ->assertSet('assigningTeams', true)
        ->call('startRound');

    return [$component, $players];
}

test('the setup screen always shows the host button, regardless of auth state', function () {
    $guest = User::createGuest('Alex');

    $this->actingAs($guest)->get('/family-feud')->assertSee('Host an Online Room');

    $user = User::factory()->create();

    $this->actingAs($user)->get('/family-feud')->assertSee('Host an Online Room');

    $this->get('/family-feud')->assertSee('Host an Online Room');
});

test('a logged-out visitor can host a room, auto-provisioning a guest account', function () {
    Livewire::test('pages::family-feud.play')
        ->set('guestName', 'Alex')
        ->call('createRoom')
        ->assertRedirect();

    $room = Room::sole();
    $host = User::find($room->host_user_id);

    expect($host->is_guest)->toBeTrue()
        ->and($host->name)->toBe('Alex');
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

test('starting the game opens a team-assignment screen, defaulting to alternating teams and captains, before any round exists', function () {
    seedFeudQuestions();

    $users = User::factory()->count(4)->create();
    $room = Room::factory()->create(['host_user_id' => $users[0]->id]);

    $players = readyUpFeudRoom($room, $users->all());

    Livewire::actingAs($users[0])
        ->test('pages::family-feud.room', ['code' => $room->code])
        ->call('onRoomStarting', $players)
        ->assertSet('assigningTeams', true)
        ->assertSet('teamAssignments', [
            $users[0]->id => 0,
            $users[1]->id => 1,
            $users[2]->id => 0,
            $users[3]->id => 1,
        ])
        ->assertSet('captainAssignments', [
            $users[0]->id => true,
            $users[1]->id => true,
            $users[2]->id => false,
            $users[3]->id => false,
        ])
        ->assertSee($users[0]->name)
        ->assertSee('Start Round');

    // No round has actually started yet -- neither the session nor the
    // seats exist until the host confirms via startRound().
    expect(GameSession::count())->toBe(0)
        ->and(RoomSeat::count())->toBe(0)
        ->and($room->fresh()->game_session_id)->toBeNull();
});

test('the host can move a player to the other team, and the vacated team keeps a captain', function () {
    seedFeudQuestions();

    $users = User::factory()->count(3)->create();
    $room = Room::factory()->create(['host_user_id' => $users[0]->id]);
    $players = readyUpFeudRoom($room, $users->all());

    // Default: team 0 = [users[0] captain, users[2]], team 1 = [users[1] captain].
    $component = Livewire::actingAs($users[0])
        ->test('pages::family-feud.room', ['code' => $room->code])
        ->call('onRoomStarting', $players);

    // Move the host (team 0's captain) onto team 1.
    $component->call('moveToTeam', $users[0]->id, 1)
        ->assertSet("teamAssignments.{$users[0]->id}", 1)
        ->assertSet("captainAssignments.{$users[0]->id}", false);

    // Team 0's only remaining member (users[2]) is automatically promoted
    // to captain rather than leaving team 0 captain-less.
    expect($component->get('captainAssignments')[$users[2]->id])->toBeTrue();
});

test('the host can hand captaincy to a different teammate', function () {
    seedFeudQuestions();

    $users = User::factory()->count(2)->create();
    $room = Room::factory()->create(['host_user_id' => $users[0]->id]);
    $players = readyUpFeudRoom($room, $users->all());

    Livewire::actingAs($users[0])
        ->test('pages::family-feud.room', ['code' => $room->code])
        ->call('onRoomStarting', $players)
        ->call('moveToTeam', $users[1]->id, 0)
        ->call('makeCaptain', $users[1]->id)
        ->assertSet("captainAssignments.{$users[0]->id}", false)
        ->assertSet("captainAssignments.{$users[1]->id}", true);
});

test('starting the round is blocked while a team has no players', function () {
    seedFeudQuestions();

    $users = User::factory()->count(2)->create();
    $room = Room::factory()->create(['host_user_id' => $users[0]->id]);
    $players = readyUpFeudRoom($room, $users->all());

    Livewire::actingAs($users[0])
        ->test('pages::family-feud.room', ['code' => $room->code])
        ->call('onRoomStarting', $players)
        ->call('moveToTeam', $users[1]->id, 0)
        ->assertSet('canStartRound', false)
        ->call('startRound')
        ->assertForbidden();

    expect(GameSession::count())->toBe(0);
});

test('confirming the assignment screen persists the host\'s customized teams and captains, not the default', function () {
    seedFeudQuestions();

    $users = User::factory()->count(3)->create();
    $room = Room::factory()->create(['host_user_id' => $users[0]->id]);
    $players = readyUpFeudRoom($room, $users->all());

    // Default would be team0=[0,2], team1=[1]. Move user 2 onto team 1 and
    // make them its captain instead of user 1.
    Livewire::actingAs($users[0])
        ->test('pages::family-feud.room', ['code' => $room->code])
        ->call('onRoomStarting', $players)
        ->call('moveToTeam', $users[2]->id, 1)
        ->call('makeCaptain', $users[2]->id)
        ->call('startRound')
        ->assertRedirect(route('family-feud.play', ['room' => $room->code]));

    $session = GameSession::sole();

    expect($session->state['teamSeats'][0])->toHaveCount(1)
        ->and($session->state['teamSeats'][1])->toHaveCount(2);

    $seatForUser2 = RoomSeat::where('room_id', $room->id)->where('user_id', $users[2]->id)->sole();
    $seatForUser1 = RoomSeat::where('room_id', $room->id)->where('user_id', $users[1]->id)->sole();

    expect($seatForUser2->team_index)->toBe(1)
        ->and($seatForUser2->is_captain)->toBeTrue()
        ->and($seatForUser1->is_captain)->toBeFalse();
});

test('starting the room twice does not create a second game session or duplicate seats', function () {
    seedFeudQuestions();

    $users = User::factory()->count(2)->create();
    $room = Room::factory()->create(['host_user_id' => $users[0]->id]);

    $players = readyUpFeudRoom($room, $users->all());

    $component = Livewire::actingAs($users[0])->test('pages::family-feud.room', ['code' => $room->code]);
    $component->call('onRoomStarting', $players);
    $component->call('startRound');
    $component->call('startRound');

    expect(GameSession::count())->toBe(1)
        ->and(RoomSeat::count())->toBe(2);
});

test('a genuine replay (via game-lobby\'s own reset) does not duplicate seat rows', function () {
    // Regression test: the previous test above only proves calling
    // startRound() twice *in a row* (game_session_id already non-null the
    // second time) is a no-op -- it never actually reset game_session_id
    // in between, so it never exercised the real replay path. A genuine
    // replay goes through game-lobby's startGame(), which *does* reset
    // game_session_id to null on every "Start Game" click, not just the
    // first -- and RoomSeat::create() used to run again unconditionally
    // inside that reopened branch, leaving the *previous* game's seat rows
    // in place and simply adding a second set on top (in fact violating a
    // real (room_id, user_id) unique constraint outright).
    seedFeudQuestions();

    $users = User::factory()->count(2)->create();
    $room = Room::factory()->create(['host_user_id' => $users[0]->id]);

    startFeudRoomWithDefaultTeams($room, $users->all());

    expect(RoomSeat::where('room_id', $room->id)->count())->toBe(2);

    startFeudRoomWithDefaultTeams($room, $users->all());

    expect(GameSession::count())->toBe(2)
        ->and(RoomSeat::where('room_id', $room->id)->count())->toBe(2);
});

test('only the controlling team\'s current turn-pointer seat can submit a guess', function () {
    seedFeudQuestions();

    $users = User::factory()->count(2)->create();
    $room = Room::factory()->create(['host_user_id' => $users[0]->id]);

    startFeudRoomWithDefaultTeams($room, $users->all());

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
    startFeudRoomWithDefaultTeams($room, $users->all());

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
    startFeudRoomWithDefaultTeams($room, $users->all());

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

    startFeudRoomWithDefaultTeams($room, $users->all());

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

    startFeudRoomWithDefaultTeams($room, $users->all());

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

    startFeudRoomWithDefaultTeams($room, $users->all());

    Livewire::actingAs($users[1])
        ->test('pages::family-feud.room', ['code' => $room->code])
        ->assertRedirect(route('family-feud.play', ['room' => $room->code]));
});

test('a full online game writes one correctly-attributed GameParticipant per seat', function () {
    seedFeudQuestions();

    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startFeudRoomWithDefaultTeams($room, [$host, $guest]);

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
