<?php

use App\Enums\FriendshipStatus;
use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\Friendship;
use App\Models\GameChallenge;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use App\Notifications\FriendRequestReceived;
use App\Notifications\GameChallengeReceived;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function gameParticipantFor(User $user, GameType $type = GameType::Boggle, int $score = 10): GameParticipant
{
    $session = GameSession::factory()->create(['type' => $type, 'mode' => GameMode::Solo]);

    return GameParticipant::factory()->create([
        'game_session_id' => $session->id,
        'user_id' => $user->id,
        'score' => $score,
    ]);
}

test('guests are redirected to login', function () {
    $this->get('/friends')->assertRedirect('/login');
});

test('the page renders for authenticated users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/friends')
        ->assertSeeLivewire('pages::friends');
});

test('searching by username surfaces a matching user with an Add button', function () {
    $user = User::factory()->create();
    $other = User::factory()->create(['username' => 'zoe-nakamura']);

    Livewire::actingAs($user)
        ->test('pages::friends')
        ->set('search', 'zoe')
        ->assertSee('zoe-nakamura')
        ->assertSeeHtml("send-request-{$other->id}");
});

test('search excludes the current user, guests, and users with no username', function () {
    $user = User::factory()->create(['username' => 'me-user']);
    User::factory()->create(['username' => null]);
    User::factory()->create(['is_guest' => true, 'username' => 'guest-user']);

    $component = Livewire::actingAs($user)
        ->test('pages::friends')
        ->set('search', 'user');

    expect($component->get('searchResults'))->toHaveCount(0);
});

test('sendRequest creates a pending friendship from the current user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create(['username' => 'target-user']);

    Livewire::actingAs($user)
        ->test('pages::friends')
        ->call('sendRequest', $other->id);

    $friendship = Friendship::sole();

    expect($friendship->requester_id)->toBe($user->id)
        ->and($friendship->addressee_id)->toBe($other->id)
        ->and($friendship->status)->toBe(FriendshipStatus::Pending);
});

test('sendRequest notifies the addressee by email', function () {
    Notification::fake();

    $user = User::factory()->create();
    $other = User::factory()->create(['username' => 'target-user']);

    Livewire::actingAs($user)
        ->test('pages::friends')
        ->call('sendRequest', $other->id);

    Notification::assertSentTo($other, FriendRequestReceived::class, function (FriendRequestReceived $notification) use ($user) {
        return $notification->requester->is($user);
    });
});

test('sendRequest does not notify a guest addressee', function () {
    Notification::fake();

    $user = User::factory()->create();
    $guest = User::createGuest('Guest Player');

    Livewire::actingAs($user)
        ->test('pages::friends')
        ->call('sendRequest', $guest->id);

    Notification::assertNothingSentTo($guest);
});

test('sendRequest is a no-op against yourself', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::friends')
        ->call('sendRequest', $user->id);

    expect(Friendship::count())->toBe(0);
});

test('sendRequest is a no-op when a request already exists in either direction', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    Friendship::factory()->create(['requester_id' => $other->id, 'addressee_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::friends')
        ->call('sendRequest', $other->id);

    expect(Friendship::count())->toBe(1);
});

test('acceptRequest accepts a request sent to the current user', function () {
    $user = User::factory()->create();
    $requester = User::factory()->create();
    $friendship = Friendship::factory()->create(['requester_id' => $requester->id, 'addressee_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::friends')
        ->call('acceptRequest', $friendship->id);

    expect($friendship->fresh()->status)->toBe(FriendshipStatus::Accepted);
});

test('acceptRequest cannot be used to accept a request sent by someone else', function () {
    $user = User::factory()->create();
    $requester = User::factory()->create();
    $addressee = User::factory()->create();
    $friendship = Friendship::factory()->create(['requester_id' => $requester->id, 'addressee_id' => $addressee->id]);

    expect(fn () => Livewire::actingAs($user)->test('pages::friends')->call('acceptRequest', $friendship->id))
        ->toThrow(ModelNotFoundException::class);
});

test('removeRequest deletes a pending request the current user sent or received', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $friendship = Friendship::factory()->create(['requester_id' => $user->id, 'addressee_id' => $other->id]);

    Livewire::actingAs($user)
        ->test('pages::friends')
        ->call('removeRequest', $friendship->id);

    expect(Friendship::find($friendship->id))->toBeNull();
});

test('removeRequest cannot be used by someone outside the request', function () {
    $user = User::factory()->create();
    $requester = User::factory()->create();
    $addressee = User::factory()->create();
    $friendship = Friendship::factory()->create(['requester_id' => $requester->id, 'addressee_id' => $addressee->id]);

    expect(fn () => Livewire::actingAs($user)->test('pages::friends')->call('removeRequest', $friendship->id))
        ->toThrow(ModelNotFoundException::class);

    expect(Friendship::find($friendship->id))->not->toBeNull();
});

test('removeFriend deletes an accepted friendship', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    Friendship::factory()->accepted()->create(['requester_id' => $user->id, 'addressee_id' => $other->id]);

    Livewire::actingAs($user)
        ->test('pages::friends')
        ->call('removeFriend', $other->id);

    expect(Friendship::count())->toBe(0);
});

test('the friends list only shows accepted relationships, from either direction', function () {
    $user = User::factory()->create();
    $acceptedAsRequester = User::factory()->create();
    $acceptedAsAddressee = User::factory()->create();
    $pending = User::factory()->create();

    Friendship::factory()->accepted()->create(['requester_id' => $user->id, 'addressee_id' => $acceptedAsRequester->id]);
    Friendship::factory()->accepted()->create(['requester_id' => $acceptedAsAddressee->id, 'addressee_id' => $user->id]);
    Friendship::factory()->create(['requester_id' => $user->id, 'addressee_id' => $pending->id]);

    $component = Livewire::actingAs($user)->test('pages::friends');

    expect($component->get('friends')->pluck('id')->sort()->values()->all())
        ->toBe([$acceptedAsRequester->id, $acceptedAsAddressee->id]);
});

test('the activity feed shows friends\' completed games but not the user\'s own or a stranger\'s', function () {
    $user = User::factory()->create();
    $friend = User::factory()->create();
    $stranger = User::factory()->create();

    Friendship::factory()->accepted()->create(['requester_id' => $user->id, 'addressee_id' => $friend->id]);

    $friendGame = gameParticipantFor($friend);
    gameParticipantFor($user);
    gameParticipantFor($stranger);

    $component = Livewire::actingAs($user)->test('pages::friends');

    expect($component->get('activity')->pluck('id')->all())->toBe([$friendGame->id]);
});

test('challengeBack notifies the friend with the link for their own game type', function () {
    Notification::fake();

    $user = User::factory()->create();
    $friend = User::factory()->create();
    Friendship::factory()->accepted()->create(['requester_id' => $user->id, 'addressee_id' => $friend->id]);

    $participant = gameParticipantFor($friend, GameType::Boggle);

    Livewire::actingAs($user)
        ->test('pages::friends')
        ->call('challengeBack', $participant->id);

    Notification::assertSentTo($friend, GameChallengeReceived::class, function (GameChallengeReceived $notification) use ($user, $participant) {
        return $notification->from->is($user)
            && $notification->gameType === GameType::Boggle
            && $notification->link === route('boggle.share', ['gameSession' => $participant->game_session_id]);
    });
});

test('received challenges from another user are listed with a Play and Dismiss action', function () {
    $user = User::factory()->create();
    $friend = User::factory()->create();
    $challenge = GameChallenge::factory()->create([
        'from_user_id' => $friend->id,
        'to_user_id' => $user->id,
        'game_type' => GameType::Boggle,
        'link' => 'https://example.test/boggle-challenge',
    ]);

    Livewire::actingAs($user)
        ->test('pages::friends')
        ->assertSee($friend->name)
        ->assertSee('Word Grid')
        ->assertSeeHtml('play-challenge-'.$challenge->id)
        ->assertSeeHtml('dismiss-challenge-'.$challenge->id);
});

test('a challenge addressed to someone else does not appear', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    GameChallenge::factory()->create(['to_user_id' => $other->id]);

    Livewire::actingAs($user)
        ->test('pages::friends')
        ->assertDontSee('Your challenges');
});

test('dismissChallenge removes a challenge addressed to the current user', function () {
    $user = User::factory()->create();
    $challenge = GameChallenge::factory()->create(['to_user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::friends')
        ->call('dismissChallenge', $challenge->id);

    expect(GameChallenge::find($challenge->id))->toBeNull();
});

test('dismissChallenge cannot be used to remove someone else\'s challenge', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $challenge = GameChallenge::factory()->create(['to_user_id' => $other->id]);

    expect(fn () => Livewire::actingAs($user)->test('pages::friends')->call('dismissChallenge', $challenge->id))
        ->toThrow(ModelNotFoundException::class);

    expect(GameChallenge::find($challenge->id))->not->toBeNull();
});

test('the activity feed offers a Challenge back button only for a challengeable game type', function () {
    $user = User::factory()->create();
    $friend = User::factory()->create();
    Friendship::factory()->accepted()->create(['requester_id' => $user->id, 'addressee_id' => $friend->id]);

    $boggleGame = gameParticipantFor($friend, GameType::Boggle);
    $yahtzeeGame = gameParticipantFor($friend, GameType::Yahtzee);

    $component = Livewire::actingAs($user)->test('pages::friends');

    $component->assertSeeHtml('challenge-back-'.$boggleGame->id)
        ->assertDontSeeHtml('challenge-back-'.$yahtzeeGame->id);
});
