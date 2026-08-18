<?php

use App\Enums\ClearTheDeckPhase;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Same two-step (game-lobby, then the game's own room page) drive as
 * AppleMatchRoomTest's own readyUpAppleMatchRoom() -- see that file's
 * docblock for why Livewire::test() needs both steps separately rather than
 * a bubbled event.
 *
 * @param  list<User>  $users
 * @return array<int, array{id: int, name: string, is_host: bool, ready: bool}>
 */
function readyUpClearTheDeckRoom(Room $room, array $users): array
{
    $component = Livewire::actingAs($users[0])->test('game-lobby', ['room' => $room, 'currentGame' => GameType::ClearTheDeck]);

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
function startClearTheDeckRoom(Room $room, array $users, ?int $roundTarget = null): Testable
{
    $players = readyUpClearTheDeckRoom($room, $users);

    $roomComponent = Livewire::actingAs($users[0])->test('pages::clear-the-deck.room', ['code' => $room->code]);

    if ($roundTarget !== null) {
        $roomComponent->call('setRoundTarget', $roundTarget);
    }

    return $roomComponent->call('onRoomStarting', $players);
}

/**
 * Also keeps GameSession.state.seatTables in sync for this seat -- real
 * gameplay always does, via syncOnlineState()'s own refreshSeatTables(),
 * so a fixture that skipped it would let a test rely on a public property
 * (getMyTableFaceUpProperty(), used by toggleFaceUpCard()'s own guard,
 * for one) that's realistically never actually stale.
 *
 * @param  list<int>  $hand
 * @param  list<int>  $faceUp
 * @param  list<int>  $faceDown
 */
function setSeatCards(Room $room, int $seatIndex, array $hand, array $faceUp, array $faceDown): void
{
    RoomSeat::where('room_id', $room->id)->where('seat_index', $seatIndex)->update([
        'hand' => $hand,
        'table_face_up' => $faceUp,
        'table_face_down' => $faceDown,
    ]);

    $session = GameSession::find($room->fresh()->game_session_id);

    if ($session === null) {
        return;
    }

    $state = $session->state;
    $state['seatTables'][$seatIndex] = [
        'handCount' => count($hand),
        'tableFaceUp' => $faceUp,
        'tableFaceDownCount' => count($faceDown),
    ];
    $session->update(['state' => $state]);
}

test('starting the room deals a 12-card hand and 4+4 table cards to every seat', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $p1, $p2]);

    $room->refresh();
    expect($room->game_session_id)->not->toBeNull();

    $seats = RoomSeat::where('room_id', $room->id)->orderBy('seat_index')->get();
    expect($seats)->toHaveCount(3);

    foreach ($seats as $seat) {
        expect($seat->hand)->toHaveCount(12);
        expect($seat->table_face_up)->toHaveCount(4);
        expect($seat->table_face_down)->toHaveCount(4);
    }

    $session = GameSession::find($room->game_session_id);
    expect($session->type)->toBe(GameType::ClearTheDeck);
    expect($session->state['phase'])->toBe(ClearTheDeckPhase::Playing->value);
    expect($session->state['dealerSeatIndex'])->toBe(0);
    expect($session->state['turnSeatIndex'])->toBe(1);
    expect($session->state['currentRound'])->toBe(1);
});

test('the room page shows the "(Tens)" clarifier in its heading', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    Livewire::actingAs($host)->test('pages::clear-the-deck.room', ['code' => $room->code])
        ->assertSee('Clear the Deck (Tens) Room');
});

test('the host landing on the room page marks the room as pending Clear the Deck, so a late-joining member can find it', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    Livewire::actingAs($host)->test('pages::clear-the-deck.room', ['code' => $room->code]);

    expect($room->fresh()->pendingGameType())->toBe(GameType::ClearTheDeck);
});

test('visiting the play page for a room whose active session belongs to a different game redirects to the room hub instead of crashing', function () {
    $user = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $user->id]);
    $session = GameSession::factory()->online()->create(['type' => GameType::Boggle, 'room_code' => $room->code]);
    $room->update(['game_session_id' => $session->id]);

    Livewire::actingAs($user)
        ->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->assertRedirect(route('room.show', ['code' => $room->code]));
});

test('starting the room with fewer than 3 players shows the host a real error instead of silently doing nothing', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $guest])
        ->assertSet('startError', 'Clear the Deck (Tens) needs between 3 and 10 players to start — 2 here right now.');

    $room->refresh();
    expect($room->game_session_id)->toBeNull();
    expect(RoomSeat::where('room_id', $room->id)->count())->toBe(0);
});

test('starting the room with more than 10 players shows the host a real error', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $users = [$host, ...User::factory()->count(10)->create()];

    startClearTheDeckRoom($room, $users)
        ->assertSet('startError', 'Clear the Deck (Tens) needs between 3 and 10 players to start — 11 here right now.');

    $room->refresh();
    expect($room->game_session_id)->toBeNull();
});

test('a legal equal-or-lesser play joins the center stack and passes the turn to the next seat', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $p1, $p2]);
    setSeatCards($room, 1, hand: [5, 9, 2], faceUp: [3], faceDown: [4, 4]);

    Livewire::actingAs($p1)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->set('selectedHandIndices', [0])
        ->call('playCards')
        ->assertSet('playError', null)
        ->assertSet('turnSeatIndex', 2)
        ->assertSet('centerStack', [5]);

    $seat1 = RoomSeat::where('room_id', $room->id)->where('seat_index', 1)->first();
    expect($seat1->hand)->toBe([9, 2]);
});

test('a four-of-a-kind clears the deck and grants the same player another turn', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $p1, $p2]);
    setSeatCards($room, 1, hand: [7, 7, 7, 7, 2], faceUp: [], faceDown: []);

    Livewire::actingAs($p1)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->set('selectedHandIndices', [0, 1, 2, 3])
        ->call('playCards')
        ->assertSet('playError', null)
        ->assertSet('turnSeatIndex', 1)
        ->assertSet('centerStack', []);
});

test('a wild Ten always clears the deck, even over a lower top card, bypassing the pickup rule', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $p1, $p2]);
    setSeatCards($room, 1, hand: [3, 4], faceUp: [], faceDown: []);
    setSeatCards($room, 2, hand: [10, 6], faceUp: [], faceDown: []);

    // p1 plays a 3, passing turn to p2 with a low top card on the stack.
    Livewire::actingAs($p1)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->set('selectedHandIndices', [0])
        ->call('playCards')
        ->assertSet('turnSeatIndex', 2);

    // p2's Ten is higher than the stack's top (3) -- would normally force a
    // pickup, but Tens always clear instead.
    Livewire::actingAs($p2)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->set('selectedHandIndices', [0])
        ->call('playCards')
        ->assertSet('playError', null)
        ->assertSet('turnSeatIndex', 2)
        ->assertSet('centerStack', []);

    $seat2 = RoomSeat::where('room_id', $room->id)->where('seat_index', 2)->first();
    // Only the Ten was removed -- no pickup happened, so the 6 stays.
    expect($seat2->hand)->toBe([6]);
});

test('playing higher than the stack forces a pickup of the whole pile and ends the turn immediately (docs/TODO.md\'s former #21)', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $p1, $p2]);
    setSeatCards($room, 1, hand: [3, 4], faceUp: [], faceDown: []);
    setSeatCards($room, 2, hand: [8, 1], faceUp: [], faceDown: []);

    Livewire::actingAs($p1)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->set('selectedHandIndices', [0])
        ->call('playCards');

    Livewire::actingAs($p2)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->set('selectedHandIndices', [0])
        ->call('playCards')
        ->assertSet('playError', null)
        // A forced pickup now passes the turn to the next seat, the same
        // as an ordinary non-set-completing play -- reversed from the
        // original design (docs/DEVLOG.md's Clear the Deck section), which
        // this test used to assert stayed on seat 2 as an extra turn.
        ->assertSet('turnSeatIndex', 0)
        ->assertSet('centerStack', []);

    $seat2 = RoomSeat::where('room_id', $room->id)->where('seat_index', 2)->first();
    // Picked up the stack's [3] plus its own played [8], on top of the 1
    // that was never played.
    expect($seat2->hand)->toEqualCanonicalizing([1, 3, 8]);
});

test('a face-down flip that matches is played and never reveals the value in the broadcast state beforehand', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $p1, $p2]);
    setSeatCards($room, 1, hand: [], faceUp: [], faceDown: [9, 9, 2, 2]);

    $room->refresh();
    $session = GameSession::find($room->game_session_id);
    foreach ($session->state['seatTables'] as $seatTable) {
        expect($seatTable)->not->toHaveKey('tableFaceDown');
        expect($seatTable)->toHaveKey('tableFaceDownCount');
    }

    Livewire::actingAs($p1)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->set('selectedFaceDownColumns', [0, 1])
        ->call('playCards')
        ->assertSet('playError', null)
        ->assertSet('centerStack', [9, 9]);

    $seat1 = RoomSeat::where('room_id', $room->id)->where('seat_index', 1)->first();
    expect($seat1->table_face_down)->toBe([2, 2]);

    $session->refresh();
    expect($session->state)->not->toHaveKey('tableFaceDown');
    foreach ($session->state['seatTables'] as $seatTable) {
        expect($seatTable)->not->toHaveKey('tableFaceDown');
    }
});

test('a mismatched face-down flip is rejected with no state change and no reveal', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $p1, $p2]);
    setSeatCards($room, 1, hand: [], faceUp: [], faceDown: [9, 3, 2, 2]);

    Livewire::actingAs($p1)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->set('selectedFaceDownColumns', [0, 1])
        ->call('playCards')
        ->assertSet('playError', "That combination doesn't share the same value.")
        ->assertSet('turnSeatIndex', 1)
        ->assertSet('centerStack', []);

    $seat1 = RoomSeat::where('room_id', $room->id)->where('seat_index', 1)->first();
    expect($seat1->table_face_down)->toBe([9, 3, 2, 2]);
});

test('toggling one face-down tile selects only that tile, not every uncovered one', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $p1, $p2]);
    setSeatCards($room, 1, hand: [], faceUp: [], faceDown: [9, 9, 9, 2]);

    Livewire::actingAs($p1)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->call('toggleFaceDownSelection', 0)
        ->assertSet('selectedFaceDownColumns', [0])
        ->call('toggleFaceDownSelection', 2)
        ->assertSet('selectedFaceDownColumns', [0, 2])
        ->call('toggleFaceDownSelection', 0)
        ->assertSet('selectedFaceDownColumns', [2]);
});

test('flipping a face-down card with other matches available pauses instead of committing immediately', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $p1, $p2]);
    setSeatCards($room, 1, hand: [6, 1], faceUp: [6], faceDown: [6, 9]);

    Livewire::actingAs($p1)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->call('toggleFaceDownSelection', 0)
        ->call('playCards')
        ->assertSet('pendingFlipValue', 6)
        ->assertSet('playError', null)
        ->assertSet('centerStack', []);

    // Nothing committed yet -- the flipped card stays right where it was.
    $seat1 = RoomSeat::where('room_id', $room->id)->where('seat_index', 1)->first();
    expect($seat1->table_face_down)->toBe([6, 9]);
    expect($seat1->hand)->toBe([6, 1]);
});

test('while a flip is pending, tapping a non-matching card does nothing', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $p1, $p2]);
    setSeatCards($room, 1, hand: [6, 1], faceUp: [6], faceDown: [6, 9]);

    Livewire::actingAs($p1)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->call('toggleFaceDownSelection', 0)
        ->call('playCards')
        ->assertSet('pendingFlipValue', 6)
        ->call('toggleHandCard', 1)
        ->assertSet('selectedHandIndices', []);
});

test('while a flip is pending, adding a matching card and confirming finalizes the combined play', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $p1, $p2]);
    setSeatCards($room, 1, hand: [6, 1], faceUp: [6], faceDown: [6, 9]);

    Livewire::actingAs($p1)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->call('toggleFaceDownSelection', 0)
        ->call('playCards')
        ->assertSet('pendingFlipValue', 6)
        ->call('toggleHandCard', 0)
        ->call('toggleFaceUpCard', 0)
        ->call('playCards')
        ->assertSet('pendingFlipValue', null)
        ->assertSet('playError', null)
        ->assertSet('centerStack', [6, 6, 6]);

    $seat1 = RoomSeat::where('room_id', $room->id)->where('seat_index', 1)->first();
    expect($seat1->hand)->toBe([1]);
    expect($seat1->table_face_up)->toBe([]);
    expect($seat1->table_face_down)->toBe([9]);
});

test('backing out of a pending flip by untoggling its face-down tile clears it', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $p1, $p2]);
    setSeatCards($room, 1, hand: [6, 1], faceUp: [6], faceDown: [6, 9]);

    Livewire::actingAs($p1)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->call('toggleFaceDownSelection', 0)
        ->call('playCards')
        ->assertSet('pendingFlipValue', 6)
        ->call('toggleFaceDownSelection', 0)
        ->assertSet('pendingFlipValue', null)
        ->assertSet('selectedFaceDownColumns', []);
});

test('confirming a pending flip with nothing added plays just the flipped card alone', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $p1, $p2]);
    setSeatCards($room, 1, hand: [6, 1], faceUp: [6], faceDown: [6, 9]);

    Livewire::actingAs($p1)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->call('toggleFaceDownSelection', 0)
        ->call('playCards')
        ->assertSet('pendingFlipValue', 6)
        ->call('playCards')
        ->assertSet('pendingFlipValue', null)
        ->assertSet('centerStack', [6]);

    $seat1 = RoomSeat::where('room_id', $room->id)->where('seat_index', 1)->first();
    expect($seat1->hand)->toBe([6, 1]);
    expect($seat1->table_face_up)->toBe([6]);
    expect($seat1->table_face_down)->toBe([9]);
});

test('a play can combine hand, face-up, and face-down cards together in one submission', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $p1, $p2]);
    setSeatCards($room, 1, hand: [6, 1], faceUp: [6], faceDown: [6, 9]);

    Livewire::actingAs($p1)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->set('selectedHandIndices', [0])
        ->call('toggleFaceUpCard', 0)
        ->call('toggleFaceDownSelection', 0)
        ->call('playCards')
        ->assertSet('playError', null)
        // Set of three 6s (hand + face-up + the matching face-down flip).
        ->assertSet('centerStack', [6, 6, 6]);

    $seat1 = RoomSeat::where('room_id', $room->id)->where('seat_index', 1)->first();
    expect($seat1->hand)->toBe([1]);
    expect($seat1->table_face_up)->toBe([]);
    expect($seat1->table_face_down)->toBe([9]);
});

test('a seat\'s display columns always pad out to 4, even once some are fully played out', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $p1, $p2]);

    // columnsForSeat() reads from the broadcast seatTables, not straight
    // from RoomSeat, so the fixture has to go through GameSession.state
    // directly -- same "set state directly" shortcut this file's other
    // tests already use.
    $room->refresh();
    $session = GameSession::find($room->game_session_id);
    $state = $session->state;
    // Only 3 cards left on the table across face-up/face-down combined.
    $state['seatTables'][1] = ['handCount' => 0, 'tableFaceUp' => [4, 9], 'tableFaceDownCount' => 1];
    $session->update(['state' => $state]);

    $columns = Livewire::actingAs($host)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->instance()->columnsForSeat(1);

    expect($columns)->toHaveCount(4);
    expect(collect($columns)->where('type', 'empty'))->toHaveCount(2);
});

test('a seat cannot play on another seat\'s turn', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $p1, $p2]);
    setSeatCards($room, 2, hand: [5], faceUp: [], faceDown: []);

    Livewire::actingAs($p2)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->set('selectedHandIndices', [0])
        ->call('playCards')
        ->assertForbidden();
});

test('an unseated visitor is always a spectator, with no seat to toggle', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $onlooker = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $p1, $p2]);

    Livewire::actingAs($onlooker)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->assertSet('mySeatIndex', null)
        ->assertSet('isSpectator', true);
});

test('a seated player can toggle Watch only to view read-only, even mid-turn, and back (docs/concerns.md)', function () {
    // Mirrors pages::yahtzee.play's own $spectate toggle exactly -- for
    // casting to a TV or screen-sharing. isMyTurn (the authorization gate
    // playCards() actually relies on) stays independent of $spectate; only
    // isSpectator (a display concern) changes.
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $p1, $p2]);

    // onRoomStarting() always seats the turn at index 1 (the appendix's
    // "player to the left of the dealer begins," dealer = seat 0) -- $p1,
    // not $host.
    $tv = Livewire::actingAs($p1)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->assertSet('isSpectator', false)
        ->assertSet('isMyTurn', true);

    $tv->set('spectate', true)
        ->assertSet('isSpectator', true)
        ->assertSet('isMyTurn', true);

    $tv->set('spectate', false)
        ->assertSet('isSpectator', false);
});

test('going out ends the round immediately, scoring everyone else\'s remaining cards with Ten worth 25', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $p1, $p2], roundTarget: 2);
    setSeatCards($room, 0, hand: [7], faceUp: [], faceDown: []);
    // p1 (seat 1) is the one taking this turn, and is left with nothing
    // after playing its only card -- the seat that actually goes out.
    setSeatCards($room, 1, hand: [5], faceUp: [], faceDown: []);
    setSeatCards($room, 2, hand: [10, 13], faceUp: [], faceDown: []);

    Livewire::actingAs($p1)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->set('selectedHandIndices', [0])
        ->call('playCards')
        ->assertSet('phase', ClearTheDeckPhase::RoundEnd)
        ->assertSet('wentOutSeatIndex', 1)
        ->assertSet('roundScores.1', 0)
        ->assertSet('roundScores.0', 7)
        // Ten scores 25, not 10; King scores its own value (13).
        ->assertSet('roundScores.2', 38);

    $room->refresh();
    expect($room->status)->toBe(RoomStatus::Active);
});

test('nextRound rotates the dealer, advances the round counter, and re-deals every seat', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $p1, $p2], roundTarget: 2);
    setSeatCards($room, 0, hand: [], faceUp: [], faceDown: []);
    setSeatCards($room, 1, hand: [5], faceUp: [], faceDown: []);

    Livewire::actingAs($p1)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->set('selectedHandIndices', [0])
        ->call('playCards');

    Livewire::actingAs($host)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->call('nextRound')
        ->assertSet('phase', ClearTheDeckPhase::Playing)
        ->assertSet('currentRound', 2)
        ->assertSet('dealerSeatIndex', 1)
        ->assertSet('turnSeatIndex', 2)
        ->assertSet('centerStack', []);

    foreach (RoomSeat::where('room_id', $room->id)->get() as $seat) {
        expect($seat->hand)->toHaveCount(12);
        expect($seat->table_face_up)->toHaveCount(4);
        expect($seat->table_face_down)->toHaveCount(4);
    }
});

test('reaching the final round ends the game and records a GameParticipant per seat, lowest score winning', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    // Default round target (1), so going out ends the game, not just a
    // round.
    startClearTheDeckRoom($room, [$host, $p1, $p2]);
    setSeatCards($room, 0, hand: [5], faceUp: [], faceDown: []);
    setSeatCards($room, 1, hand: [6], faceUp: [], faceDown: []);
    setSeatCards($room, 2, hand: [9], faceUp: [], faceDown: []);

    // Force seat 0 (the host) to be the active player so it's the one who
    // goes out, rather than waiting through seat 1's own real turn.
    $room->refresh();
    $session = GameSession::find($room->game_session_id);
    $state = $session->state;
    $state['turnSeatIndex'] = 0;
    $session->update(['state' => $state]);

    Livewire::actingAs($host)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->set('selectedHandIndices', [0])
        ->call('playCards')
        ->assertSet('phase', ClearTheDeckPhase::GameEnd);

    $room->refresh();
    expect($room->status)->toBe(RoomStatus::Finished);

    $session->refresh();
    expect($session->ended_at)->not->toBeNull();

    $participants = GameParticipant::where('game_session_id', $session->id)->get();
    expect($participants)->toHaveCount(3);

    $winner = $participants->firstWhere('user_id', $host->id);
    expect($winner->result)->toBe(GameResult::Win);
    expect($winner->score)->toBe(0);

    $loser = $participants->firstWhere('user_id', $p2->id);
    expect($loser->result)->toBe(GameResult::Loss);
    expect($loser->score)->toBe(9);
});

test('a tied lowest score at game end records a Draw for every tied seat', function () {
    $host = User::factory()->create();
    $p1 = User::factory()->create();
    $p2 = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);

    startClearTheDeckRoom($room, [$host, $p1, $p2]);
    // The active seat (0) goes out, but starts this round already deep in
    // the hole from prior rounds, so it does *not* end up the lowest --
    // that's the point of this fixture: the other two seats (already
    // empty-handed, so this round adds nothing further to either) are the
    // ones who actually tie for lowest.
    setSeatCards($room, 0, hand: [5], faceUp: [], faceDown: []);
    setSeatCards($room, 1, hand: [], faceUp: [], faceDown: []);
    setSeatCards($room, 2, hand: [], faceUp: [], faceDown: []);

    $room->refresh();
    $session = GameSession::find($room->game_session_id);
    $state = $session->state;
    $state['turnSeatIndex'] = 0;
    $session->update(['state' => $state]);

    Livewire::actingAs($host)->test('pages::clear-the-deck.play', ['room' => $room->code])
        ->set('scores', [0 => 10, 1 => 2, 2 => 2])
        ->set('selectedHandIndices', [0])
        ->call('playCards')
        ->assertSet('phase', ClearTheDeckPhase::GameEnd);

    $participants = GameParticipant::where('game_session_id', $session->id)->get();

    $p1Result = $participants->firstWhere('user_id', $p1->id);
    $p2Result = $participants->firstWhere('user_id', $p2->id);

    expect($p1Result->result)->toBe(GameResult::Draw);
    expect($p2Result->result)->toBe(GameResult::Draw);
    expect($p1Result->score)->toBe(2);
    expect($p2Result->score)->toBe(2);
});
