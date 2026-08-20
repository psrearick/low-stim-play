<?php

use App\Enums\FriendshipStatus;
use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\Friendship;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
