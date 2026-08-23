<?php

use App\Enums\GameResult;
use App\Enums\GameType;
use App\Enums\PunchlineMatchupStatus;
use App\Enums\PunchlinePhase;
use App\Enums\RoomStatus;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\PunchlinePrompt;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedPunchlinePrompts(int $count = 40): void
{
    PunchlinePrompt::factory()->count($count)->create();
}

/**
 * Same two-step (game-lobby, then the game's own room page) drive as
 * AppleMatchRoomTest's own readyUpAppleMatchRoom() -- see that file's
 * docblock for why Livewire::test() needs both steps separately rather
 * than a bubbled event.
 *
 * @param  list<User>  $users
 * @return array<int, array{id: int, name: string, is_host: bool, ready: bool}>
 */
function readyUpPunchlineRoom(Room $room, array $users): array
{
    $component = Livewire::actingAs($users[0])->test('game-lobby', ['room' => $room, 'currentGame' => GameType::Punchline]);

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
function startPunchlineRoom(Room $room, array $users): Testable
{
    $players = readyUpPunchlineRoom($room, $users);

    return Livewire::actingAs($users[0])
        ->test('pages::punchline.room', ['code' => $room->code])
        ->call('onRoomStarting', $players);
}

/**
 * @param  list<User>  $users
 * @return array<int, RoomSeat>
 */
function punchlineSeatsByUser(Room $room, array $users): array
{
    return collect($users)
        ->mapWithKeys(fn (User $user) => [$user->id => RoomSeat::where('room_id', $room->id)->where('user_id', $user->id)->first()])
        ->all();
}

/**
 * Writes a placeholder answer to every still-open slot in every matchup of
 * the room's *current* round, for every seat -- the shared "get through a
 * round's writing phase" step several tests below reuse verbatim.
 *
 * @param  array<int, User>  $usersBySeat
 */
function autoplayPunchlineWriting(Room $room, array $usersBySeat): void
{
    $session = GameSession::find($room->game_session_id);

    foreach ($session->state['matchups'] as $matchup) {
        foreach ($matchup['seatIndices'] as $seatIndex) {
            $user = $usersBySeat[$seatIndex];
            for ($slot = 0; $slot < $matchup['answersPerSeat']; $slot++) {
                Livewire::actingAs($user)->test('pages::punchline.play', ['room' => $room->code])
                    ->set("drafts.{$matchup['id']}-{$slot}", "seat {$seatIndex} slot {$slot}")
                    ->call('submitAnswer', $matchup['id']);
            }
        }
    }
}

/**
 * Casts exactly one vote from every eligible voter on every open matchup
 * in the room's current round, picking any answer that isn't the voter's
 * own -- the shared "get through a round's voting phase, don't care who
 * wins" step several tests below reuse verbatim.
 *
 * @param  array<int, User>  $usersBySeat
 */
function autoplayPunchlineVoting(Room $room, array $usersBySeat): void
{
    $session = GameSession::find($room->game_session_id);

    foreach ($session->state['matchups'] as $matchup) {
        $everyone = array_column($session->state['seats'], 'seatIndex');
        $eligible = array_values(array_diff($everyone, $matchup['seatIndices']));
        if ($eligible === []) {
            $eligible = $matchup['seatIndices'];
        }

        foreach ($eligible as $voterSeat) {
            $ownAnswerIds = collect($matchup['answers'])
                ->filter(fn (array $a) => str_starts_with($a['answerId'], "{$voterSeat}-"))
                ->pluck('answerId')
                ->all();
            $choice = collect($matchup['answers'])->pluck('answerId')->reject(fn ($id) => in_array($id, $ownAnswerIds, true))->first();

            if ($choice === null) {
                continue;
            }

            Livewire::actingAs($usersBySeat[$voterSeat])->test('pages::punchline.play', ['room' => $room->code])
                ->call('castVote', $matchup['id'], [$choice]);
        }
    }
}

test('starting a 4-player room builds the exact hand-verified round 1 pairing, no room_seats columns needed', function () {
    seedPunchlinePrompts();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $p3 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startPunchlineRoom($room, [$host, $p1, $p2, $p3]);

    $room->refresh();
    expect($room->game_session_id)->not->toBeNull();
    expect($room->status)->toBe(RoomStatus::Active);

    $seats = RoomSeat::where('room_id', $room->id)->orderBy('seat_index')->get();
    expect($seats)->toHaveCount(4);

    $session = GameSession::find($room->game_session_id);
    expect($session->type)->toBe(GameType::Punchline);
    expect($session->state['round'])->toBe(1);
    expect($session->state['phase'])->toBe(PunchlinePhase::Writing->value);
    expect($session->state['pool'])->toBe(1000);
    expect($session->state['scores'])->toBe([0, 0, 0, 0]);

    // Matches PairingServiceTest's own hand-verified P=4 output exactly.
    $matchups = $session->state['matchups'];
    expect($matchups)->toHaveCount(4);
    expect(array_column($matchups, 'seatIndices'))->toBe([
        [3, 0], [1, 2], [3, 2], [0, 1],
    ]);

    foreach ($matchups as $matchup) {
        expect($matchup['status'])->toBe(PunchlineMatchupStatus::Writing->value);
        expect($matchup['answersPerSeat'])->toBe(1);
    }
});

test('starting the room outside the 3-8 player range shows the host a real error instead of silently doing nothing', function () {
    seedPunchlinePrompts();
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startPunchlineRoom($room, [$host, $guest])
        ->assertSet('startError', 'Punchline needs between 3 and 8 players to start — 2 here right now.');

    $room->refresh();
    expect($room->game_session_id)->toBeNull();
    expect(RoomSeat::where('room_id', $room->id)->count())->toBe(0);
});

test('starting the room with more than the enforced 8-player maximum also shows a real error, unlike every other room game\'s unenforced ceiling', function () {
    seedPunchlinePrompts();
    $users = User::factory()->count(9)->create()->all();
    $room = Room::factory()->create(['host_user_id' => $users[0]->id]);

    startPunchlineRoom($room, $users)
        ->assertSet('startError', 'Punchline needs between 3 and 8 players to start — 9 here right now.');

    $room->refresh();
    expect($room->game_session_id)->toBeNull();
});

test('a matchup opens for voting only once both its authors have submitted, revealing answers with no author name attached', function () {
    seedPunchlinePrompts();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $p3 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startPunchlineRoom($room, [$host, $p1, $p2, $p3]);
    $room->refresh();

    // r1-1 is seats [1, 2] = p1 & p2.
    Livewire::actingAs($p1)->test('pages::punchline.play', ['room' => $room->code])
        ->call('submitAnswer', 'r1-1'); // no draft text set -> rejected

    $session = GameSession::find($room->game_session_id);
    $matchup = collect($session->state['matchups'])->firstWhere('id', 'r1-1');
    expect($matchup['status'])->toBe(PunchlineMatchupStatus::Writing->value);

    Livewire::actingAs($p1)->test('pages::punchline.play', ['room' => $room->code])
        ->set('drafts.r1-1-0', 'Answer from p1')
        ->call('submitAnswer', 'r1-1');

    $session = GameSession::find($room->game_session_id);
    $matchup = collect($session->state['matchups'])->firstWhere('id', 'r1-1');
    expect($matchup['status'])->toBe(PunchlineMatchupStatus::Writing->value); // p2 hasn't gone yet

    Livewire::actingAs($p2)->test('pages::punchline.play', ['room' => $room->code])
        ->set('drafts.r1-1-0', 'Answer from p2')
        ->call('submitAnswer', 'r1-1');

    $session = GameSession::find($room->game_session_id);
    $matchup = collect($session->state['matchups'])->firstWhere('id', 'r1-1');
    expect($matchup['status'])->toBe(PunchlineMatchupStatus::Voting->value);
    expect($matchup['answers'])->toHaveCount(2);

    foreach ($matchup['answers'] as $answer) {
        expect($answer)->toHaveKeys(['answerId', 'text'])->not->toHaveKey('seatIndex');
    }

    $texts = array_column($matchup['answers'], 'text');
    expect($texts)->toContain('Answer from p1', 'Answer from p2');
});

test('an author cannot vote on their own matchup, but an eligible non-author can, and a sweep awards a bonus on top of the full pool', function () {
    seedPunchlinePrompts();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $p3 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startPunchlineRoom($room, [$host, $p1, $p2, $p3]);
    $room->refresh();
    $seats = punchlineSeatsByUser($room, [$host, $p1, $p2, $p3]);

    // r1-1 is seats [1, 2] = p1 & p2; eligible voters are seats 0 & 3 (host, p3).
    Livewire::actingAs($p1)->test('pages::punchline.play', ['room' => $room->code])->set('drafts.r1-1-0', 'p1 answer')->call('submitAnswer', 'r1-1');
    Livewire::actingAs($p2)->test('pages::punchline.play', ['room' => $room->code])->set('drafts.r1-1-0', 'p2 answer')->call('submitAnswer', 'r1-1');

    $session = GameSession::find($room->game_session_id);
    $matchup = collect($session->state['matchups'])->firstWhere('id', 'r1-1');
    $p1AnswerId = collect($matchup['answers'])->firstWhere('text', 'p1 answer')['answerId'];

    // p1 (an author of this matchup) cannot vote on it.
    Livewire::actingAs($p1)->test('pages::punchline.play', ['room' => $room->code])
        ->call('castVote', 'r1-1', [$p1AnswerId])
        ->assertForbidden();

    // Both eligible voters (host, p3) vote for p1's answer -> a 2-0 sweep.
    Livewire::actingAs($host)->test('pages::punchline.play', ['room' => $room->code])
        ->call('castVote', 'r1-1', [$p1AnswerId]);

    Livewire::actingAs($p3)->test('pages::punchline.play', ['room' => $room->code])
        ->call('castVote', 'r1-1', [$p1AnswerId]);

    $session = GameSession::find($room->game_session_id);
    $matchup = collect($session->state['matchups'])->firstWhere('id', 'r1-1');
    expect($matchup['status'])->toBe(PunchlineMatchupStatus::Closed->value);
    expect($matchup['results']['sweep'])->toBeTrue();
    expect($matchup['results']['sweepBonus'])->toBe(200); // round50(1000 * 0.2)
    expect($matchup['results']['pointsByAnswerId'][$p1AnswerId])->toBe(1200); // 1000 + 200

    // The real author mapping is only revealed now that the matchup closed.
    expect($matchup['results']['authorsByAnswerId'][$p1AnswerId])->toBe($seats[$p1->id]->seat_index);

    expect($session->state['scores'][$seats[$p1->id]->seat_index])->toBe(1200);
    expect($session->state['scores'][$seats[$p2->id]->seat_index])->toBe(0);
});

test('nobody can vote twice, and votes for an answer id from a different matchup are rejected', function () {
    seedPunchlinePrompts();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $p3 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startPunchlineRoom($room, [$host, $p1, $p2, $p3]);
    $room->refresh();

    Livewire::actingAs($p1)->test('pages::punchline.play', ['room' => $room->code])->set('drafts.r1-1-0', 'p1 answer')->call('submitAnswer', 'r1-1');
    Livewire::actingAs($p2)->test('pages::punchline.play', ['room' => $room->code])->set('drafts.r1-1-0', 'p2 answer')->call('submitAnswer', 'r1-1');

    $session = GameSession::find($room->game_session_id);
    $matchup = collect($session->state['matchups'])->firstWhere('id', 'r1-1');
    $answerId = $matchup['answers'][0]['answerId'];

    Livewire::actingAs($host)->test('pages::punchline.play', ['room' => $room->code])
        ->call('castVote', 'r1-1', [$answerId])
        ->call('castVote', 'r1-1', [$answerId])
        ->assertForbidden();

    Livewire::actingAs($p3)->test('pages::punchline.play', ['room' => $room->code])
        ->call('castVote', 'r1-1', ['not-a-real-answer-id'])
        ->assertStatus(422);
});

test('a P=3 game falls back to authors voting on each other\'s answers within the same trio matchup', function () {
    seedPunchlinePrompts();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startPunchlineRoom($room, [$host, $p1, $p2]);
    $room->refresh();

    $session = GameSession::find($room->game_session_id);
    // P=3 degenerates to the same trio answering both prompts (PairingServiceTest).
    expect(array_column($session->state['matchups'], 'seatIndices'))->toBe([
        [0, 1, 2], [0, 1, 2],
    ]);

    Livewire::actingAs($host)->test('pages::punchline.play', ['room' => $room->code])->set('drafts.r1-0-0', 'host answer')->call('submitAnswer', 'r1-0');
    Livewire::actingAs($p1)->test('pages::punchline.play', ['room' => $room->code])->set('drafts.r1-0-0', 'p1 answer')->call('submitAnswer', 'r1-0');
    Livewire::actingAs($p2)->test('pages::punchline.play', ['room' => $room->code])->set('drafts.r1-0-0', 'p2 answer')->call('submitAnswer', 'r1-0');

    $session = GameSession::find($room->game_session_id);
    $matchup = collect($session->state['matchups'])->firstWhere('id', 'r1-0');
    expect($matchup['status'])->toBe(PunchlineMatchupStatus::Voting->value);

    $hostAnswerId = collect($matchup['answers'])->firstWhere('text', 'host answer')['answerId'];
    $p1AnswerId = collect($matchup['answers'])->firstWhere('text', 'p1 answer')['answerId'];

    // host cannot vote for their own answer...
    Livewire::actingAs($host)->test('pages::punchline.play', ['room' => $room->code])
        ->call('castVote', 'r1-0', [$hostAnswerId])
        ->assertForbidden();

    // ...but can vote for someone else's, even though host is also an author of this matchup.
    Livewire::actingAs($host)->test('pages::punchline.play', ['room' => $room->code])
        ->call('castVote', 'r1-0', [$p1AnswerId])
        ->assertOk();
});

test('secret ballot state never leaks into the broadcast game session state', function () {
    seedPunchlinePrompts();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $p3 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startPunchlineRoom($room, [$host, $p1, $p2, $p3]);
    $room->refresh();

    Livewire::actingAs($p1)->test('pages::punchline.play', ['room' => $room->code])->set('drafts.r1-1-0', 'p1 answer')->call('submitAnswer', 'r1-1');
    Livewire::actingAs($p2)->test('pages::punchline.play', ['room' => $room->code])->set('drafts.r1-1-0', 'p2 answer')->call('submitAnswer', 'r1-1');
    Livewire::actingAs($host)->test('pages::punchline.play', ['room' => $room->code])->call('castVote', 'r1-1', [
        collect(GameSession::find($room->game_session_id)->state['matchups'])->firstWhere('id', 'r1-1')['answers'][0]['answerId'],
    ]);

    $session = GameSession::find($room->game_session_id);
    $stateJson = json_encode($session->state);

    expect($stateJson)->not->toContain('votes');

    foreach ($session->state['matchups'] as $matchup) {
        expect($matchup)->not->toHaveKey('votes');

        foreach ($matchup['answers'] as $answer) {
            expect($answer)->not->toHaveKey('seatIndex');
        }

        // A still-open matchup must not reveal authorship yet.
        if ($matchup['status'] !== PunchlineMatchupStatus::Closed->value) {
            expect($matchup['results'])->toBeNull();
        }
    }
});

test('a writing timeout auto-fills every missing answer and moves the round to voting', function () {
    seedPunchlinePrompts();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $p3 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startPunchlineRoom($room, [$host, $p1, $p2, $p3]);
    $room->refresh();

    $session = GameSession::find($room->game_session_id);
    $state = $session->state;
    $state['writingEndsAt'] = now()->subSecond()->timestamp;
    $session->update(['state' => $state]);

    Livewire::actingAs($host)->test('pages::punchline.play', ['room' => $room->code])
        ->call('checkTimeout')
        ->assertSet('phase', PunchlinePhase::Voting);

    $session = GameSession::find($room->game_session_id);
    foreach ($session->state['matchups'] as $matchup) {
        expect($matchup['status'])->toBe(PunchlineMatchupStatus::Voting->value);
        expect($matchup['answers'])->toHaveCount(count($matchup['seatIndices']));
    }
});

test('a full 3-round game ends with one GameParticipant per seat carrying pointsByRound and sweepsWon stats', function () {
    seedPunchlinePrompts();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $p3 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startPunchlineRoom($room, [$host, $p1, $p2, $p3]);
    $room->refresh();
    $usersBySeat = [$host, $p1, $p2, $p3];

    for ($round = 1; $round <= 3; $round++) {
        autoplayPunchlineWriting($room, $usersBySeat);
        autoplayPunchlineVoting($room, $usersBySeat);

        $session = GameSession::find($room->game_session_id);
        expect($session->state['phase'])->toBe(PunchlinePhase::RoundReveal->value);

        Livewire::actingAs($host)->test('pages::punchline.play', ['room' => $room->code])->call('advanceRound');
    }

    $room->refresh();
    expect($room->status)->toBe(RoomStatus::Finished);

    $session = GameSession::find($room->game_session_id);
    expect($session->ended_at)->not->toBeNull();
    expect($session->state['phase'])->toBe(PunchlinePhase::GameEnd->value);

    $participants = GameParticipant::where('game_session_id', $session->id)->get();
    expect($participants)->toHaveCount(4);

    foreach ($participants as $participant) {
        expect($participant->stats)->toHaveKeys(['pointsByRound', 'sweepsWon']);
        expect($participant->stats['pointsByRound'])->toHaveCount(3);
        expect($participant->result)->toBeIn([GameResult::Win, GameResult::Loss]);
    }

    expect($participants->sum('score'))->toBeGreaterThan(0);
});

test('round 3 lets a voter stack all 3 votes on a single answer, and every seat is both an author and an eligible voter on the same shared matchup', function () {
    seedPunchlinePrompts();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $p3 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startPunchlineRoom($room, [$host, $p1, $p2, $p3]);
    $room->refresh();
    $usersBySeat = [$host, $p1, $p2, $p3];
    $seats = punchlineSeatsByUser($room, [$host, $p1, $p2, $p3]);

    // Play through rounds 1-2 for real so round 3 is reached honestly,
    // rather than hand-crafting its state and risking it drifting out of
    // sync with what advanceRound() actually builds.
    foreach ([1, 2] as $round) {
        autoplayPunchlineWriting($room, $usersBySeat);
        autoplayPunchlineVoting($room, $usersBySeat);
        Livewire::actingAs($host)->test('pages::punchline.play', ['room' => $room->code])->call('advanceRound');
    }

    $session = GameSession::find($room->game_session_id);
    expect($session->state['round'])->toBe(3);
    expect($session->state['pool'])->toBe(3000);
    expect($session->state['matchups'])->toHaveCount(1);
    expect($session->state['matchups'][0]['seatIndices'])->toBe([0, 1, 2, 3]);
    expect($session->state['matchups'][0]['answersPerSeat'])->toBe(3);

    // Every seat writes its 3 answers.
    autoplayPunchlineWriting($room, $usersBySeat);

    $session = GameSession::find($room->game_session_id);
    $matchup = $session->state['matchups'][0];
    expect($matchup['status'])->toBe(PunchlineMatchupStatus::Voting->value);
    expect($matchup['answers'])->toHaveCount(12); // 4 seats x 3 answers

    $p1AnswerId = collect($matchup['answers'])->firstWhere('text', "seat {$seats[$p1->id]->seat_index} slot 0")['answerId'];

    // host stacks all 3 votes on the same p1 answer.
    Livewire::actingAs($host)->test('pages::punchline.play', ['room' => $room->code])
        ->call('adjustVoteAllocation', $p1AnswerId, 1)
        ->call('adjustVoteAllocation', $p1AnswerId, 1)
        ->call('adjustVoteAllocation', $p1AnswerId, 1)
        ->assertSet("voteAllocation.{$p1AnswerId}", 3)
        ->call('submitRoundThreeVotes', 'r3-0')
        ->assertOk();

    // A 4th vote is rejected -- the allocation UI itself caps at 3, but the
    // server-side castVote() guard is the one that actually matters.
    Livewire::actingAs($p2)->test('pages::punchline.play', ['room' => $room->code])
        ->call('castVote', 'r3-0', [$p1AnswerId, $p1AnswerId, $p1AnswerId, $p1AnswerId])
        ->assertStatus(422);

    $session = GameSession::find($room->game_session_id);
    $tally = collect($session->state['matchups'][0]['votedSeats']);
    expect($tally)->toContain($seats[$host->id]->seat_index)
        ->not->toContain($seats[$p2->id]->seat_index); // p2's rejected call never recorded a vote
});

test('a voting timeout closes every still-open matchup using the zero-vote fallback (even pool split) instead of stalling forever', function () {
    seedPunchlinePrompts();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $p3 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startPunchlineRoom($room, [$host, $p1, $p2, $p3]);
    $room->refresh();
    $usersBySeat = [$host, $p1, $p2, $p3];

    autoplayPunchlineWriting($room, $usersBySeat);

    $session = GameSession::find($room->game_session_id);
    expect($session->state['phase'])->toBe(PunchlinePhase::Voting->value);

    $state = $session->state;
    $state['votingEndsAt'] = now()->subSecond()->timestamp;
    $session->update(['state' => $state]);

    Livewire::actingAs($host)->test('pages::punchline.play', ['room' => $room->code])
        ->call('checkTimeout')
        ->assertSet('phase', PunchlinePhase::RoundReveal);

    $session = GameSession::find($room->game_session_id);

    foreach ($session->state['matchups'] as $matchup) {
        expect($matchup['status'])->toBe(PunchlineMatchupStatus::Closed->value);
        expect($matchup['results']['totalVotes'])->toBe(0);

        // Even split -- round50(1000 / 2) = 500 each for a plain pair.
        foreach ($matchup['results']['pointsByAnswerId'] as $points) {
            expect($points)->toBe((int) round(1000 / count($matchup['seatIndices']) / 50) * 50);
        }
    }
});
