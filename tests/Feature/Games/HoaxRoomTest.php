<?php

use App\Enums\GameResult;
use App\Enums\GameType;
use App\Enums\HoaxPhase;
use App\Enums\RoomStatus;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\HoaxQuestion;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use App\Services\Hoax\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedHoaxQuestions(int $count = 10): void
{
    HoaxQuestion::factory()->count($count)->create();
}

/**
 * Same two-step (game-lobby, then the game's own room page) drive as
 * PunchlineRoomTest's own readyUpPunchlineRoom() -- see that file's
 * docblock for why Livewire::test() needs both steps separately rather
 * than a bubbled event.
 *
 * @param  list<User>  $users
 * @return array<int, array{id: int, name: string, is_host: bool, ready: bool}>
 */
function readyUpHoaxRoom(Room $room, array $users): array
{
    $component = Livewire::actingAs($users[0])->test('game-lobby', ['room' => $room, 'currentGame' => GameType::Hoax]);

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
 * @param  list<User>  $users
 */
function startHoaxRoom(Room $room, array $users): Testable
{
    $players = readyUpHoaxRoom($room, $users);

    return Livewire::actingAs($users[0])
        ->test('pages::hoax.room', ['code' => $room->code])
        ->call('onRoomStarting', $players);
}

/**
 * @param  list<User>  $users
 * @return array<int, RoomSeat>
 */
function hoaxSeatsByUser(Room $room, array $users): array
{
    return collect($users)
        ->mapWithKeys(fn (User $user) => [$user->id => RoomSeat::where('room_id', $room->id)->where('user_id', $user->id)->first()])
        ->all();
}

/**
 * Writes a placeholder answer for every seat that hasn't submitted yet in
 * the room's current round.
 *
 * @param  array<int, User>  $usersBySeat
 */
function autoplayHoaxWriting(Room $room, array $usersBySeat): void
{
    foreach ($usersBySeat as $seatIndex => $user) {
        Livewire::actingAs($user)->test('pages::hoax.play', ['room' => $room->code])
            ->set('draftAnswer', "seat {$seatIndex} answer")
            ->call('submitAnswer');
    }
}

/**
 * Casts exactly one vote from every seat, picking any answer that isn't
 * their own.
 *
 * @param  array<int, User>  $usersBySeat
 */
function autoplayHoaxVoting(Room $room, array $usersBySeat): void
{
    foreach ($usersBySeat as $seatIndex => $user) {
        $session = GameSession::find($room->game_session_id);
        $choice = collect($session->state['answers'])
            ->reject(fn (array $a) => str_starts_with($a['answerId'], "{$seatIndex}-"))
            ->first();

        if ($choice === null) {
            continue;
        }

        Livewire::actingAs($user)->test('pages::hoax.play', ['room' => $room->code])
            ->call('castVote', $choice['answerId']);
    }
}

/**
 * docs/concerns.md: the pre-lobby screen and the waiting room used to be
 * the same screen, and the instant this page loaded the room was already
 * marked pending -- everyone could join before the host had even decided
 * to run this game. openLobby() is the new explicit line between the two.
 */
test('landing on the room page does not mark the room pending until the host opens the lobby', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    $component = Livewire::actingAs($host)->test('pages::hoax.room', ['code' => $room->code]);

    expect($room->fresh()->pendingGameType())->toBeNull();

    $component->call('openLobby');

    expect($room->fresh()->pendingGameType())->toBe(GameType::Hoax);
});

test('a non-host cannot open the lobby', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $room->addMember($guest);

    Livewire::actingAs($guest)
        ->test('pages::hoax.room', ['code' => $room->code])
        ->call('openLobby')
        ->assertForbidden();

    expect($room->fresh()->pendingGameType())->toBeNull();
});

test('a non-host sees a waiting message instead of the pre-lobby screen or the ready-up UI before the host opens the lobby', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $room->addMember($guest);

    Livewire::actingAs($guest)
        ->test('pages::hoax.room', ['code' => $room->code])
        ->assertSee('Waiting for the host to finish setting up')
        ->assertDontSee('fixed 5-round game')
        ->assertDontSee('Ready');
});

test('starting a 3-player room builds round 1\'s shared question and empty ballot state', function () {
    seedHoaxQuestions();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startHoaxRoom($room, [$host, $p1, $p2]);

    $room->refresh();
    expect($room->game_session_id)->not->toBeNull();
    expect($room->status)->toBe(RoomStatus::Active);

    $seats = RoomSeat::where('room_id', $room->id)->orderBy('seat_index')->get();
    expect($seats)->toHaveCount(3);

    $session = GameSession::find($room->game_session_id);
    expect($session->type)->toBe(GameType::Hoax);
    expect($session->state['round'])->toBe(1);
    expect($session->state['phase'])->toBe(HoaxPhase::Writing->value);
    expect($session->state['scores'])->toBe([0, 0, 0]);
    expect($session->state['submittedSeats'])->toBe([]);
    expect($session->state['votedSeats'])->toBe([]);
    expect($session->state['answers'])->toBe([]);
    expect($session->state['questionId'])->not->toBeNull();
    expect($session->state['questionText'])->toContain('____');
});

test('starting the room outside the 3-8 player range shows the host a real error instead of silently doing nothing', function () {
    seedHoaxQuestions();
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startHoaxRoom($room, [$host, $guest])
        ->assertSet('startError', 'Hoax needs between 3 and 8 players to start — 2 here right now.');

    $room->refresh();
    expect($room->game_session_id)->toBeNull();
    expect(RoomSeat::where('room_id', $room->id)->count())->toBe(0);
});

test('starting the room with more than the enforced 8-player maximum also shows a real error', function () {
    seedHoaxQuestions();
    $users = User::factory()->count(9)->create()->all();
    $room = Room::factory()->create(['host_user_id' => $users[0]->id]);

    startHoaxRoom($room, $users)
        ->assertSet('startError', 'Hoax needs between 3 and 8 players to start — 9 here right now.');

    $room->refresh();
    expect($room->game_session_id)->toBeNull();
});

test('starting the room with an unseeded question bank shows a real error', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startHoaxRoom($room, [$host, $p1, $p2])
        ->assertSet('startError', 'Hoax\'s question bank hasn\'t been seeded yet -- ask whoever runs this deployment to run its database seeder.');

    $room->refresh();
    expect($room->game_session_id)->toBeNull();
});

test('writing opens voting once every seat has submitted, revealing an anonymized list that includes the real answer', function () {
    seedHoaxQuestions();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startHoaxRoom($room, [$host, $p1, $p2]);
    $room->refresh();
    $usersBySeat = [$host, $p1, $p2];

    autoplayHoaxWriting($room, $usersBySeat);

    $session = GameSession::find($room->game_session_id);
    expect($session->state['phase'])->toBe(HoaxPhase::Voting->value);
    // 3 fake answers + the real one, no author info leaked.
    expect($session->state['answers'])->toHaveCount(4);

    foreach ($session->state['answers'] as $answer) {
        expect($answer)->toHaveKeys(['answerId', 'text'])->not->toHaveKey('seatIndex');
    }

    $texts = array_column($session->state['answers'], 'text');
    foreach (range(0, 2) as $seatIndex) {
        expect($texts)->toContain("seat {$seatIndex} answer");
    }
});

test('submitting the exact real answer is rejected and not counted as submitted', function () {
    seedHoaxQuestions();
    $question = HoaxQuestion::factory()->create(['answer' => 'Nebraska', 'alternate_answers' => ['NE']]);
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startHoaxRoom($room, [$host, $p1, $p2]);
    $room->refresh();

    // Force this round onto the question with a known answer, so the
    // rejection check has something deterministic to compare against --
    // pickQuestion() would otherwise choose any of the seeded questions.
    $session = GameSession::find($room->game_session_id);
    $state = $session->state;
    $state['questionId'] = $question->id;
    $state['questionText'] = $question->text;
    $session->update(['state' => $state]);

    Livewire::actingAs($host)->test('pages::hoax.play', ['room' => $room->code])
        ->set('draftAnswer', 'nebraska') // different case, still the real answer
        ->call('submitAnswer')
        ->assertOk()
        ->assertSet('submitError', 'That\'s the real answer -- write something else to try to fool people instead.')
        ->assertSet('draftAnswer', 'nebraska'); // left in place so the player can edit it

    $session = GameSession::find($room->game_session_id);
    expect($session->state['submittedSeats'])->toBe([]);
});

test('submitting a known alternate spelling of the real answer is also rejected', function () {
    seedHoaxQuestions();
    $question = HoaxQuestion::factory()->create(['answer' => 'Nebraska', 'alternate_answers' => ['NE']]);
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startHoaxRoom($room, [$host, $p1, $p2]);
    $room->refresh();

    $session = GameSession::find($room->game_session_id);
    $state = $session->state;
    $state['questionId'] = $question->id;
    $state['questionText'] = $question->text;
    $session->update(['state' => $state]);

    Livewire::actingAs($host)->test('pages::hoax.play', ['room' => $room->code])
        ->set('draftAnswer', 'ne')
        ->call('submitAnswer')
        ->assertSet('submitError', 'That\'s the real answer -- write something else to try to fool people instead.');

    $session = GameSession::find($room->game_session_id);
    expect($session->state['submittedSeats'])->toBe([]);
});

test('after a rejected submission, writing a genuinely different answer still submits successfully', function () {
    seedHoaxQuestions();
    $question = HoaxQuestion::factory()->create(['answer' => 'Nebraska', 'alternate_answers' => ['NE']]);
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startHoaxRoom($room, [$host, $p1, $p2]);
    $room->refresh();

    $session = GameSession::find($room->game_session_id);
    $state = $session->state;
    $state['questionId'] = $question->id;
    $state['questionText'] = $question->text;
    $session->update(['state' => $state]);

    $hostComponent = Livewire::actingAs($host)->test('pages::hoax.play', ['room' => $room->code])
        ->set('draftAnswer', 'Nebraska')
        ->call('submitAnswer')
        ->assertSet('submitError', 'That\'s the real answer -- write something else to try to fool people instead.');

    $hostComponent->set('draftAnswer', 'a live tarantula')
        ->call('submitAnswer')
        ->assertSet('submitError', null);

    $session = GameSession::find($room->game_session_id);
    expect($session->state['submittedSeats'])->toBe([0]);
});

test('a voter never sees their own answer as a votable option, but does see the real answer', function () {
    seedHoaxQuestions();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startHoaxRoom($room, [$host, $p1, $p2]);
    $room->refresh();
    $usersBySeat = [$host, $p1, $p2];

    autoplayHoaxWriting($room, $usersBySeat);

    $hostComponent = Livewire::actingAs($host)->test('pages::hoax.play', ['room' => $room->code]);
    $votable = collect($hostComponent->instance()->votableAnswers())->pluck('text');

    expect($votable)->not->toContain('seat 0 answer')
        ->toContain('seat 1 answer', 'seat 2 answer')
        ->toHaveCount(3); // 2 other players' answers + the truth
});

test('voting closes the round once everyone has voted, awarding truth points and fool points correctly', function () {
    seedHoaxQuestions();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startHoaxRoom($room, [$host, $p1, $p2]);
    $room->refresh();
    $usersBySeat = [$host, $p1, $p2];
    $seats = hoaxSeatsByUser($room, [$host, $p1, $p2]);

    autoplayHoaxWriting($room, $usersBySeat);

    $session = GameSession::find($room->game_session_id);
    $truthAnswerId = collect($session->state['answers'])->first(fn (array $a) => $a['answerId'] === 'truth')['answerId'];
    $seat0AnswerId = collect($session->state['answers'])->first(fn (array $a) => $a['text'] === 'seat 0 answer')['answerId'];

    // p1 and p2 both vote for seat 0's lie -- host votes for the truth.
    Livewire::actingAs($p1)->test('pages::hoax.play', ['room' => $room->code])->call('castVote', $seat0AnswerId);
    Livewire::actingAs($p2)->test('pages::hoax.play', ['room' => $room->code])->call('castVote', $seat0AnswerId);
    Livewire::actingAs($host)->test('pages::hoax.play', ['room' => $room->code])->call('castVote', $truthAnswerId);

    $session = GameSession::find($room->game_session_id);
    expect($session->state['phase'])->toBe(HoaxPhase::RoundReveal->value);

    $hostSeat = $seats[$host->id]->seat_index;
    $p1Seat = $seats[$p1->id]->seat_index;
    $p2Seat = $seats[$p2->id]->seat_index;

    // Host wrote seat 0's lie, which fooled 2 voters (2 x FOOL_POINTS),
    // *and* separately voted for the truth themself (+ TRUTH_POINTS) --
    // both roles stack onto the same seat's total in the same round.
    expect($session->state['scores'][$hostSeat])->toBe(2 * ScoringService::FOOL_POINTS + ScoringService::TRUTH_POINTS);
    // p1 and p2 wrote lies nobody voted for, and didn't vote for the
    // truth themselves -- nothing earned either way.
    expect($session->state['scores'][$p1Seat])->toBe(0);
    expect($session->state['scores'][$p2Seat])->toBe(0);
    expect($session->state['results']['truthAnswerId'])->toBe($truthAnswerId);
    expect($session->state['results']['seatsWhoFoundTruth'])->toBe([$hostSeat]);
});

test('a second vote from the same seat is a quiet no-op, and an unknown answer id is rejected', function () {
    seedHoaxQuestions();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startHoaxRoom($room, [$host, $p1, $p2]);
    $room->refresh();
    $usersBySeat = [$host, $p1, $p2];

    autoplayHoaxWriting($room, $usersBySeat);

    $session = GameSession::find($room->game_session_id);
    $answerId = collect($session->state['answers'])->first(fn (array $a) => ! str_starts_with($a['answerId'], '0-'))['answerId'];

    Livewire::actingAs($host)->test('pages::hoax.play', ['room' => $room->code])
        ->call('castVote', $answerId)
        ->call('castVote', $answerId)
        ->assertOk();

    $session = GameSession::find($room->game_session_id);
    expect($session->state['votedSeats'])->toBe([0]);

    Livewire::actingAs($p1)->test('pages::hoax.play', ['room' => $room->code])
        ->call('castVote', 'not-a-real-answer-id')
        ->assertStatus(422);
});

test('casting a vote for your own answer is silently dropped, not recorded', function () {
    seedHoaxQuestions();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startHoaxRoom($room, [$host, $p1, $p2]);
    $room->refresh();
    $usersBySeat = [$host, $p1, $p2];

    autoplayHoaxWriting($room, $usersBySeat);

    $session = GameSession::find($room->game_session_id);
    $ownAnswerId = collect($session->state['answers'])->first(fn (array $a) => $a['text'] === 'seat 0 answer')['answerId'];

    Livewire::actingAs($host)->test('pages::hoax.play', ['room' => $room->code])
        ->call('castVote', $ownAnswerId)
        ->assertOk();

    $session = GameSession::find($room->game_session_id);
    expect($session->state['votedSeats'])->toBe([]);
});

test('secret ballot state never leaks into the broadcast game session state', function () {
    seedHoaxQuestions();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startHoaxRoom($room, [$host, $p1, $p2]);
    $room->refresh();

    Livewire::actingAs($host)->test('pages::hoax.play', ['room' => $room->code])
        ->set('draftAnswer', 'p1 answer')
        ->call('submitAnswer');

    $session = GameSession::find($room->game_session_id);
    $stateJson = json_encode($session->state);

    expect($stateJson)->not->toContain('votes');

    foreach ($session->state['answers'] as $answer) {
        expect($answer)->not->toHaveKey('seatIndex');
    }
});

test('a writing timeout auto-fills every missing answer and moves the round to voting', function () {
    seedHoaxQuestions();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startHoaxRoom($room, [$host, $p1, $p2]);
    $room->refresh();

    Livewire::actingAs($host)->test('pages::hoax.play', ['room' => $room->code])
        ->set('draftAnswer', 'host answer')
        ->call('submitAnswer');

    $session = GameSession::find($room->game_session_id);
    $state = $session->state;
    $state['writingEndsAt'] = now()->subSecond()->timestamp;
    GameSession::find($room->game_session_id)->update(['state' => $state]);

    Livewire::actingAs($p1)->test('pages::hoax.play', ['room' => $room->code])->call('checkTimeout');

    $session = GameSession::find($room->game_session_id);
    expect($session->state['phase'])->toBe(HoaxPhase::Voting->value);
    expect($session->state['submittedSeats'])->toHaveCount(3);
    expect($session->state['answers'])->toHaveCount(4); // 3 seats + truth
});

test('a voting timeout closes the round using whatever votes exist so far', function () {
    seedHoaxQuestions();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startHoaxRoom($room, [$host, $p1, $p2]);
    $room->refresh();
    $usersBySeat = [$host, $p1, $p2];

    autoplayHoaxWriting($room, $usersBySeat);

    $session = GameSession::find($room->game_session_id);
    $state = $session->state;
    $state['votingEndsAt'] = now()->subSecond()->timestamp;
    GameSession::find($room->game_session_id)->update(['state' => $state]);

    Livewire::actingAs($host)->test('pages::hoax.play', ['room' => $room->code])->call('checkTimeout');

    $session = GameSession::find($room->game_session_id);
    expect($session->state['phase'])->toBe(HoaxPhase::RoundReveal->value);
    expect($session->state['results'])->not->toBeNull();
});

test('a full 5-round game ends with one GameParticipant per seat carrying pointsByRound and truthsFound stats', function () {
    seedHoaxQuestions();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startHoaxRoom($room, [$host, $p1, $p2]);
    $room->refresh();
    $usersBySeat = [$host, $p1, $p2];

    for ($round = 1; $round <= 5; $round++) {
        autoplayHoaxWriting($room, $usersBySeat);
        autoplayHoaxVoting($room, $usersBySeat);

        $session = GameSession::find($room->game_session_id);
        expect($session->state['phase'])->toBe(HoaxPhase::RoundReveal->value);

        Livewire::actingAs($host)->test('pages::hoax.play', ['room' => $room->code])->call('advanceRound');
    }

    $room->refresh();
    expect($room->status)->toBe(RoomStatus::Finished);

    $session = GameSession::find($room->game_session_id);
    expect($session->ended_at)->not->toBeNull();
    expect($session->state['phase'])->toBe(HoaxPhase::GameEnd->value);

    $participants = GameParticipant::where('game_session_id', $session->id)->get();
    expect($participants)->toHaveCount(3);

    foreach ($participants as $participant) {
        expect($participant->stats)->toHaveKeys(['pointsByRound', 'truthsFound']);
        expect($participant->stats['pointsByRound'])->toHaveCount(5);
        expect($participant->result)->toBeIn([GameResult::Win, GameResult::Loss]);
    }
});

test('a non-host clicking Next Round is forbidden', function () {
    seedHoaxQuestions();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startHoaxRoom($room, [$host, $p1, $p2]);
    $room->refresh();
    $usersBySeat = [$host, $p1, $p2];

    autoplayHoaxWriting($room, $usersBySeat);
    autoplayHoaxVoting($room, $usersBySeat);

    $session = GameSession::find($room->game_session_id);
    expect($session->state['phase'])->toBe(HoaxPhase::RoundReveal->value);

    Livewire::actingAs($p1)->test('pages::hoax.play', ['room' => $room->code])
        ->call('advanceRound')
        ->assertForbidden();

    $session = GameSession::find($room->game_session_id);
    expect($session->state['round'])->toBe(1);
});

test('the host double-clicking Next Round (a stale-snapshot race against themselves) does not error or double-advance', function () {
    seedHoaxQuestions();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startHoaxRoom($room, [$host, $p1, $p2]);
    $room->refresh();
    $usersBySeat = [$host, $p1, $p2];

    autoplayHoaxWriting($room, $usersBySeat);
    autoplayHoaxVoting($room, $usersBySeat);

    $hostComponent = Livewire::actingAs($host)->test('pages::hoax.play', ['room' => $room->code]);

    Livewire::actingAs($host)->test('pages::hoax.play', ['room' => $room->code])->call('advanceRound');

    $session = GameSession::find($room->game_session_id);
    expect($session->state['round'])->toBe(2);

    $hostComponent->call('advanceRound')->assertOk();

    $session = GameSession::find($room->game_session_id);
    expect($session->state['round'])->toBe(2);
});

test('the host can end the game early, scoring whatever partial round progress exists so far', function () {
    seedHoaxQuestions();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startHoaxRoom($room, [$host, $p1, $p2]);
    $room->refresh();

    Livewire::actingAs($host)->test('pages::hoax.play', ['room' => $room->code])->call('endGameManually');

    $room->refresh();
    expect($room->status)->toBe(RoomStatus::Finished);

    $session = GameSession::find($room->game_session_id);
    expect($session->state['phase'])->toBe(HoaxPhase::GameEnd->value);
    expect(GameParticipant::where('game_session_id', $session->id)->count())->toBe(3);
});

test('a non-host cannot end the game early', function () {
    seedHoaxQuestions();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startHoaxRoom($room, [$host, $p1, $p2]);
    $room->refresh();

    Livewire::actingAs($p1)->test('pages::hoax.play', ['room' => $room->code])
        ->call('endGameManually')
        ->assertForbidden();

    $room->refresh();
    expect($room->status)->toBe(RoomStatus::Active);
});

test('voting after the round already closed elsewhere while this connection was stale re-syncs quietly instead of erroring', function () {
    seedHoaxQuestions();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startHoaxRoom($room, [$host, $p1, $p2]);
    $room->refresh();
    $usersBySeat = [$host, $p1, $p2];

    autoplayHoaxWriting($room, $usersBySeat);

    // host's own connection mounts here, while voting is still open.
    $hostComponent = Livewire::actingAs($host)->test('pages::hoax.play', ['room' => $room->code]);
    expect($hostComponent->get('phase'))->toBe(HoaxPhase::Voting);

    // The voting deadline passes and p2's connection triggers
    // checkTimeout(), force-closing the round -- host's already-mounted
    // component has no way to know this happened.
    $session = GameSession::find($room->game_session_id);
    $state = $session->state;
    $state['votingEndsAt'] = now()->subSecond()->timestamp;
    GameSession::find($room->game_session_id)->update(['state' => $state]);
    Livewire::actingAs($p2)->test('pages::hoax.play', ['room' => $room->code])->call('checkTimeout');

    $answerId = $session->state['answers'][0]['answerId'];

    // host clicks a vote against their own stale ("still voting") view --
    // must not error.
    $hostComponent->call('castVote', $answerId)->assertOk();

    $session = GameSession::find($room->game_session_id);
    expect($session->state['phase'])->toBe(HoaxPhase::RoundReveal->value);
});

test('submitting an answer after writing already closed elsewhere while this connection was stale re-syncs quietly instead of erroring', function () {
    seedHoaxQuestions();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startHoaxRoom($room, [$host, $p1, $p2]);
    $room->refresh();

    // p1's own connection mounts here, while writing is still open.
    $p1Component = Livewire::actingAs($p1)->test('pages::hoax.play', ['room' => $room->code]);
    expect($p1Component->get('phase'))->toBe(HoaxPhase::Writing);

    // The writing deadline passes and p2's connection triggers
    // checkTimeout(), auto-filling every missing answer (including p1's)
    // and moving to voting.
    $session = GameSession::find($room->game_session_id);
    $state = $session->state;
    $state['writingEndsAt'] = now()->subSecond()->timestamp;
    GameSession::find($room->game_session_id)->update(['state' => $state]);
    Livewire::actingAs($p2)->test('pages::hoax.play', ['room' => $room->code])->call('checkTimeout');

    // p1 submits against their own stale ("still writing") view -- must
    // not error.
    $p1Component->set('draftAnswer', 'too late now')->call('submitAnswer')->assertOk();

    $session = GameSession::find($room->game_session_id);
    expect($session->state['phase'])->toBe(HoaxPhase::Voting->value);
    // p1's late text never actually got submitted -- the auto-fill
    // already took that slot before it arrived.
    expect(collect($session->state['answers'])->pluck('text'))->not->toContain('too late now');
});
