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
        ->test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy'])
        ->call('giveUp')
        ->assertSee('Challenge a Friend');

    $date = now()->startOfDay()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::pips.play', ['dailyDate' => $date, 'difficulty' => 'easy'])
        ->call('giveUp')
        ->assertDontSee('Challenge a Friend');
});

test('the button is absent for a guest', function () {
    Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy'])
        ->call('giveUp')
        ->assertDontSee('Challenge a Friend');
});

test('sending a challenge notifies the selected friend with the direct seed+difficulty link', function () {
    Notification::fake();

    $user = User::factory()->create();
    $friend = User::factory()->create();
    Friendship::factory()->accepted()->create(['requester_id' => $user->id, 'addressee_id' => $friend->id]);

    $link = route('pips.play', ['seed' => 42, 'difficulty' => 'hard']);

    Livewire::actingAs($user)
        ->test('challenge-friend', ['gameType' => GameType::Pips, 'link' => $link])
        ->call('send', $friend->id);

    Notification::assertSentTo($friend, GameChallengeReceived::class, function (GameChallengeReceived $notification) use ($user, $link) {
        return $notification->from->is($user)
            && $notification->gameType === GameType::Pips
            && $notification->link === $link;
    });
});

test('the challenge link resolves via GameChallengeService to a direct seed+difficulty link', function () {
    $session = GameSession::factory()->create(['type' => GameType::Pips, 'settings' => ['seed' => 42, 'difficulty' => 'hard']]);
    $participant = GameParticipant::factory()->create(['game_session_id' => $session->id]);

    $link = app(GameChallengeService::class)->linkFor($participant);

    expect($link)->toBe(route('pips.play', ['seed' => 42, 'difficulty' => 'hard']));
});
