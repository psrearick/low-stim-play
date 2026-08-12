<?php

use App\Enums\AppleMatchPhase;
use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\AppleMatchCard;
use App\Models\AppleMatchDeck;
use App\Models\AppleMatchPrompt;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Pest\Browser\Api\PendingAwaitablePage;

uses(RefreshDatabase::class);

/**
 * Same single-real-browser-plus-DB-seeded-state convention as
 * tests/Browser/FamilyFeudRoomBrowserTest.php -- see that file's own
 * docblock for why (two concurrent visit() pages reliably hang in this
 * environment, and BROADCAST_CONNECTION=null means a second live browser
 * would never actually receive a broadcast during a test anyway). Three
 * seats (the game's own minimum, see docs/specs/apple-match.md) with the
 * host (seat 0) as round 1's judge, so this covers submitter vs. judge vs.
 * spectator visibility -- tests/Feature/Games/AppleMatchRoomTest.php
 * already covers the submit/judge authorization logic in depth.
 *
 * @return array{0: Room, 1: GameSession, 2: Collection<int, User>, 3: array<int, int>}
 */
function startedAppleMatchRoom(): array
{
    $host = User::factory()->create(['password' => bcrypt('password')]);
    $guest1 = User::factory()->create(['password' => bcrypt('password')]);
    $guest2 = User::factory()->create(['password' => bcrypt('password')]);
    $users = collect([$host, $guest1, $guest2]);

    $deck = AppleMatchDeck::factory()->create(['owner_user_id' => null, 'source' => 'system']);
    AppleMatchPrompt::factory()->count(5)->create(['deck_id' => $deck->id]);
    $cards = AppleMatchCard::factory()->count(30)->create(['deck_id' => $deck->id]);
    $cardIds = $cards->pluck('id')->all();

    $hands = [
        0 => array_slice($cardIds, 0, 7),
        1 => array_slice($cardIds, 7, 7),
        2 => array_slice($cardIds, 14, 7),
    ];

    $room = Room::factory()->active()->create(['host_user_id' => $host->id]);

    $promptId = AppleMatchPrompt::where('deck_id', $deck->id)->first()->id;

    $state = [
        'deckId' => $deck->id,
        'targetScore' => 4,
        'seats' => [
            ['seatIndex' => 0, 'name' => $host->name],
            ['seatIndex' => 1, 'name' => $guest1->name],
            ['seatIndex' => 2, 'name' => $guest2->name],
        ],
        'scores' => [0, 0, 0],
        'judgedCounts' => [0, 0, 0],
        'judgeSeatIndex' => 0,
        'round' => 1,
        'phase' => AppleMatchPhase::Submitting->value,
        'usedPromptIds' => [$promptId],
        'currentPromptId' => $promptId,
        'currentPromptText' => 'Disgusting',
        'submittedSeats' => [],
        'anonymizedSubmissions' => [],
        'winningText' => null,
        'winningSeatName' => null,
    ];

    $session = GameSession::factory()->create([
        'type' => GameType::AppleMatch,
        'mode' => GameMode::Online,
        'settings' => ['target_score' => 4, 'deck_id' => $deck->id],
        'state' => $state,
    ]);

    $room->update(['game_session_id' => $session->id]);

    RoomSeat::factory()->create(['room_id' => $room->id, 'seat_index' => 0, 'user_id' => $host->id, 'hand' => $hands[0]]);
    RoomSeat::factory()->create(['room_id' => $room->id, 'seat_index' => 1, 'user_id' => $guest1->id, 'hand' => $hands[1]]);
    RoomSeat::factory()->create(['room_id' => $room->id, 'seat_index' => 2, 'user_id' => $guest2->id, 'hand' => $hands[2]]);

    return [$room, $session, $users, $hands];
}

/**
 * A real login through the actual form -- not actingAs(), which only sets
 * the container's resolved guard user and never touches a real session
 * cookie, so it wouldn't survive into a real browser request.
 */
function loginAsAndVisitAppleMatch(User $user, string $path): PendingAwaitablePage
{
    $page = visit('/login');

    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('#login-submit');

    $page->navigate($path);

    return $page;
}

test('a non-judge seat sees their own hand and can submit a card', function () {
    [$room, , $users, $hands] = startedAppleMatchRoom();
    $card = AppleMatchCard::find($hands[1][0]);

    $page = loginAsAndVisitAppleMatch($users[1], "/apple-match?room={$room->code}");

    $page->assertSee('Disgusting')
        ->assertSee($card->text)
        ->assertDontSee('Waiting for the rest of the table')
        ->click($card->text);

    $page->assertDontSee($card->text)
        ->assertSee('Waiting for the rest of the table');
});

test('the judge sees a waiting count instead of a hand', function () {
    [$room, , $users] = startedAppleMatchRoom();

    $page = loginAsAndVisitAppleMatch($users->first(), "/apple-match?room={$room->code}");

    $page->assertSee('Waiting for everyone to submit')
        ->assertDontSee('Pick your best match');
});

test('an unseated spectator sees no hand and no submit controls', function () {
    [$room] = startedAppleMatchRoom();
    $spectator = User::factory()->create(['password' => bcrypt('password')]);

    $page = loginAsAndVisitAppleMatch($spectator, "/apple-match?room={$room->code}");

    $page->assertDontSee('Pick your best match')
        ->assertSee('Watching');
});
