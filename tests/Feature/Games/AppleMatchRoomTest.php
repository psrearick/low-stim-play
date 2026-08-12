<?php

use App\Enums\AppleMatchPhase;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Models\AppleMatchCard;
use App\Models\AppleMatchDeck;
use App\Models\AppleMatchPrompt;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedAppleMatchDeck(int $prompts = 10, int $cards = 60): AppleMatchDeck
{
    $deck = AppleMatchDeck::factory()->create(['owner_user_id' => null, 'source' => 'system']);
    AppleMatchPrompt::factory()->count($prompts)->create(['deck_id' => $deck->id]);
    AppleMatchCard::factory()->count($cards)->create(['deck_id' => $deck->id]);

    return $deck;
}

/**
 * Same two-step (game-lobby, then the game's own room page) drive as
 * YahtzeeRoomTest's own readyUpAndStart() -- see that file's docblock for
 * why Livewire::test() needs both steps separately rather than a bubbled
 * event.
 *
 * @param  list<User>  $users
 * @return array<int, array{id: int, name: string, is_host: bool, ready: bool}>
 */
function readyUpAppleMatchRoom(Room $room, array $users): array
{
    $component = Livewire::actingAs($users[0])->test('game-lobby', ['room' => $room, 'currentGame' => GameType::AppleMatch]);

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
function startAppleMatchRoom(Room $room, array $users): Testable
{
    $players = readyUpAppleMatchRoom($room, $users);

    return Livewire::actingAs($users[0])
        ->test('pages::apple-match.room', ['code' => $room->code])
        ->call('onRoomStarting', $players);
}

test('starting the room deals a 7-card hand to every seat and creates the round state', function () {
    seedAppleMatchDeck();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startAppleMatchRoom($room, [$host, $p1, $p2]);

    $room->refresh();
    expect($room->game_session_id)->not->toBeNull();

    $seats = RoomSeat::where('room_id', $room->id)->orderBy('seat_index')->get();
    expect($seats)->toHaveCount(3);

    foreach ($seats as $seat) {
        expect($seat->hand)->toHaveCount(7);
    }

    $session = GameSession::find($room->game_session_id);
    expect($session->type)->toBe(GameType::AppleMatch);
    expect($session->state['phase'])->toBe(AppleMatchPhase::Submitting->value);
    expect($session->state['judgeSeatIndex'])->toBe(0);
    expect($session->state['round'])->toBe(1);
});

test('starting the room with fewer than 3 players shows the host a real error instead of silently doing nothing', function () {
    seedAppleMatchDeck();
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startAppleMatchRoom($room, [$host, $guest])
        ->assertSet('startError', 'Best Match needs at least 3 players to start — 2 here right now.');

    $room->refresh();
    expect($room->game_session_id)->toBeNull();
    expect(RoomSeat::where('room_id', $room->id)->count())->toBe(0);
});

test('a full round: submit, judge picks a winner, next round rotates the judge and replenishes hands', function () {
    seedAppleMatchDeck();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startAppleMatchRoom($room, [$host, $p1, $p2]);
    $room->refresh();

    $seat1 = RoomSeat::where('room_id', $room->id)->where('user_id', $p1->id)->first();
    $seat2 = RoomSeat::where('room_id', $room->id)->where('user_id', $p2->id)->first();
    $cardFromP1 = $seat1->hand[0];
    $cardFromP2 = $seat2->hand[0];

    Livewire::actingAs($p1)->test('pages::apple-match.play', ['room' => $room->code])
        ->call('submitCard', $cardFromP1)
        ->assertSet('phase', AppleMatchPhase::Submitting);

    Livewire::actingAs($p2)->test('pages::apple-match.play', ['room' => $room->code])
        ->call('submitCard', $cardFromP2)
        ->assertSet('phase', AppleMatchPhase::Judging);

    Livewire::actingAs($host)->test('pages::apple-match.play', ['room' => $room->code])
        ->assertSet('isJudge', true)
        ->call('pickWinner', $cardFromP1)
        ->assertSet('phase', AppleMatchPhase::RoundEnd)
        ->assertSet("scores.{$seat1->seat_index}", 1)
        ->call('nextRound')
        ->assertSet('phase', AppleMatchPhase::Submitting)
        ->assertSet('judgeSeatIndex', 1)
        ->assertSet('round', 2);

    $seat1->refresh();
    // Seat 1 (p1) played a card and wasn't the outgoing judge, so it's
    // replenished back to a full hand -- unlike the new judge (also seat
    // 1... no, judgeSeatIndex is now 1, so seat 1 is judging round 2 and
    // never submitted at all this past round, meaning its hand simply
    // never dropped below 7 in the first place).
    expect($seat1->hand)->toHaveCount(7);
});

test('the judge cannot submit a card', function () {
    seedAppleMatchDeck();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startAppleMatchRoom($room, [$host, $p1, $p2]);
    $room->refresh();

    $hostSeat = RoomSeat::where('room_id', $room->id)->where('user_id', $host->id)->first();

    Livewire::actingAs($host)->test('pages::apple-match.play', ['room' => $room->code])
        ->call('submitCard', $hostSeat->hand[0])
        ->assertForbidden();
});

test('a seat cannot submit twice in the same round', function () {
    seedAppleMatchDeck();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startAppleMatchRoom($room, [$host, $p1, $p2]);
    $room->refresh();

    $seat1 = RoomSeat::where('room_id', $room->id)->where('user_id', $p1->id)->first();
    $cards = $seat1->hand;

    Livewire::actingAs($p1)->test('pages::apple-match.play', ['room' => $room->code])
        ->call('submitCard', $cards[0])
        ->call('submitCard', $cards[1])
        ->assertForbidden();
});

test('a non-judge seat cannot pick a winner', function () {
    seedAppleMatchDeck();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startAppleMatchRoom($room, [$host, $p1, $p2]);
    $room->refresh();

    $seat1 = RoomSeat::where('room_id', $room->id)->where('user_id', $p1->id)->first();
    $seat2 = RoomSeat::where('room_id', $room->id)->where('user_id', $p2->id)->first();
    $cardFromP1 = $seat1->hand[0];
    $cardFromP2 = $seat2->hand[0];

    Livewire::actingAs($p1)->test('pages::apple-match.play', ['room' => $room->code])->call('submitCard', $cardFromP1);
    Livewire::actingAs($p2)->test('pages::apple-match.play', ['room' => $room->code])->call('submitCard', $cardFromP2);

    Livewire::actingAs($p1)->test('pages::apple-match.play', ['room' => $room->code])
        ->call('pickWinner', $cardFromP1)
        ->assertForbidden();
});

test('round attribution never appears in the broadcast game session state', function () {
    seedAppleMatchDeck();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startAppleMatchRoom($room, [$host, $p1, $p2]);
    $room->refresh();

    $seat1 = RoomSeat::where('room_id', $room->id)->where('user_id', $p1->id)->first();
    $seat2 = RoomSeat::where('room_id', $room->id)->where('user_id', $p2->id)->first();

    Livewire::actingAs($p1)->test('pages::apple-match.play', ['room' => $room->code])->call('submitCard', $seat1->hand[0]);
    Livewire::actingAs($p2)->test('pages::apple-match.play', ['room' => $room->code])->call('submitCard', $seat2->hand[0]);

    $session = GameSession::find($room->game_session_id);

    // The revealed submissions carry text only -- never a seat/user
    // mapping. See docs/specs/apple-match.md section 3 and the class-level
    // notes on pages::apple-match.play's own submitCard()/pickWinner().
    expect($session->state)->not->toHaveKey('submissions');
    expect($session->state)->not->toHaveKey('attribution');

    foreach ($session->state['anonymizedSubmissions'] as $submission) {
        expect($submission)->toHaveKeys(['cardId', 'text']);
        expect($submission)->not->toHaveKey('seatIndex');
        expect($submission)->not->toHaveKey('userId');
    }
});

test('reaching the target score ends the game and records a GameParticipant per seat', function () {
    seedAppleMatchDeck();
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startAppleMatchRoom($room, [$host, $p1, $p2]);
    $room->refresh();

    $seat1 = RoomSeat::where('room_id', $room->id)->where('user_id', $p1->id)->first();
    $seat2 = RoomSeat::where('room_id', $room->id)->where('user_id', $p2->id)->first();
    $cardFromP1 = $seat1->hand[0];
    $cardFromP2 = $seat2->hand[0];

    Livewire::actingAs($p1)->test('pages::apple-match.play', ['room' => $room->code])->call('submitCard', $cardFromP1);
    Livewire::actingAs($p2)->test('pages::apple-match.play', ['room' => $room->code])->call('submitCard', $cardFromP2);

    // Fast-forward seat 1 to one green card away from the (default) target
    // of 4, same "set state directly, then trigger the action" shortcut
    // YahtzeeRoomTest/FamilyFeudRoomTest already use for their own
    // multi-round scenarios.
    Livewire::actingAs($host)->test('pages::apple-match.play', ['room' => $room->code])
        ->set('scores', [0 => 0, 1 => 3, 2 => 0])
        ->call('pickWinner', $cardFromP1)
        ->assertSet('phase', AppleMatchPhase::GameEnd);

    $room->refresh();
    expect($room->status)->toBe(RoomStatus::Finished);

    $session = GameSession::find($room->game_session_id);
    expect($session->ended_at)->not->toBeNull();

    $participants = GameParticipant::where('game_session_id', $session->id)->get();
    expect($participants)->toHaveCount(3);

    $winner = $participants->firstWhere('user_id', $p1->id);
    expect($winner->result)->toBe(GameResult::Win);
    expect($winner->score)->toBe(4);
    expect($winner->stats['green_cards_won'])->toBe(4);

    $loser = $participants->firstWhere('user_id', $p2->id);
    expect($loser->result)->toBe(GameResult::Loss);
});
