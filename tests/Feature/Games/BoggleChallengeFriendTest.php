<?php

use App\Enums\GameType;
use App\Models\Friendship;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use App\Notifications\GameChallengeReceived;
use App\Services\Social\GameChallengeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('the reveal screen offers a Challenge a Friend button only for a signed-in, truly solo game', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 0])
        ->call('endGame')
        ->assertSee('Challenge a Friend');
});

test('the button is absent for a guest', function () {
    Livewire::test('pages::boggle.play', ['size' => 4, 'minutes' => 0])
        ->call('endGame')
        ->assertDontSee('Challenge a Friend');
});

test('sending a challenge notifies the selected friend with the boggle.share link', function () {
    Notification::fake();

    $user = User::factory()->create();
    $friend = User::factory()->create();
    Friendship::factory()->accepted()->create(['requester_id' => $user->id, 'addressee_id' => $friend->id]);

    $link = route('boggle.share', ['gameSession' => 42]);

    Livewire::actingAs($user)
        ->test('challenge-friend', ['gameType' => GameType::Boggle, 'link' => $link])
        ->call('send', $friend->id);

    Notification::assertSentTo($friend, GameChallengeReceived::class, function (GameChallengeReceived $notification) use ($user, $link) {
        return $notification->from->is($user)
            && $notification->gameType === GameType::Boggle
            && $notification->link === $link;
    });
});

test('a guest friend is never notified', function () {
    Notification::fake();

    $user = User::factory()->create();
    $guestFriend = User::factory()->create(['is_guest' => true]);
    Friendship::factory()->accepted()->create(['requester_id' => $user->id, 'addressee_id' => $guestFriend->id]);

    Livewire::actingAs($user)
        ->test('challenge-friend', ['gameType' => GameType::Boggle, 'link' => route('boggle.lobby')])
        ->assertDontSee($guestFriend->name);

    Notification::assertNothingSent();
});

test("the challenge link resolves via GameChallengeService to boggle's own shared session link", function () {
    $session = GameSession::factory()->create(['type' => GameType::Boggle]);
    $participant = GameParticipant::factory()->create(['game_session_id' => $session->id]);

    $link = app(GameChallengeService::class)->linkFor($participant);

    expect($link)->toBe(route('boggle.share', ['gameSession' => $session->id]));
});
