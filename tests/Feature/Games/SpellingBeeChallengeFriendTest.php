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

test('the reveal screen offers a Challenge a Friend button for a signed-in player, but not a daily', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::spelling-bee.play', ['seed' => 1])
        ->call('endGame')
        ->assertSee('Challenge a Friend');

    $date = now()->startOfDay()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::spelling-bee.play', ['dailyDate' => $date])
        ->call('endGame')
        ->assertDontSee('Challenge a Friend');
});

test('the button is absent for a guest', function () {
    Livewire::test('pages::spelling-bee.play', ['seed' => 1])
        ->call('endGame')
        ->assertDontSee('Challenge a Friend');
});

test('sending a challenge notifies the selected friend with the direct seed link', function () {
    Notification::fake();

    $user = User::factory()->create();
    $friend = User::factory()->create();
    Friendship::factory()->accepted()->create(['requester_id' => $user->id, 'addressee_id' => $friend->id]);

    $link = route('spelling-bee.play', ['seed' => 42]);

    Livewire::actingAs($user)
        ->test('challenge-friend', ['gameType' => GameType::SpellingBee, 'link' => $link])
        ->call('send', $friend->id);

    Notification::assertSentTo($friend, GameChallengeReceived::class, function (GameChallengeReceived $notification) use ($user, $link) {
        return $notification->from->is($user)
            && $notification->gameType === GameType::SpellingBee
            && $notification->link === $link;
    });
});

test('the challenge link resolves via GameChallengeService to a direct seed link', function () {
    $session = GameSession::factory()->create(['type' => GameType::SpellingBee, 'settings' => ['seed' => 42]]);
    $participant = GameParticipant::factory()->create(['game_session_id' => $session->id]);

    $link = app(GameChallengeService::class)->linkFor($participant);

    expect($link)->toBe(route('spelling-bee.play', ['seed' => 42]));
});
