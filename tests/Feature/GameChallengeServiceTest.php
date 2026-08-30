<?php

use App\Enums\GameType;
use App\Models\GameChallenge;
use App\Models\User;
use App\Services\Social\GameChallengeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('send is a no-op for a guest recipient, notifying nobody and persisting nothing', function () {
    Notification::fake();

    $user = User::factory()->create();
    $guest = User::createGuest('Guest Player');

    app(GameChallengeService::class)->send($user, $guest, 'https://example.test/boggle', GameType::Boggle);

    expect(GameChallenge::count())->toBe(0);
    Notification::assertNothingSent();
});

test('receivedFor returns only challenges addressed to that user, newest first', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $older = GameChallenge::factory()->create(['to_user_id' => $user->id, 'created_at' => now()->subMinute()]);
    $newer = GameChallenge::factory()->create(['to_user_id' => $user->id]);
    GameChallenge::factory()->create(['to_user_id' => $other->id]);

    $received = app(GameChallengeService::class)->receivedFor($user);

    expect($received->pluck('id')->all())->toBe([$newer->id, $older->id]);
});

test('dismiss deletes the challenge row', function () {
    $challenge = GameChallenge::factory()->create();

    app(GameChallengeService::class)->dismiss($challenge);

    expect(GameChallenge::find($challenge->id))->toBeNull();
});
