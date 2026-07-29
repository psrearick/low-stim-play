<?php

use App\Enums\GameResult;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a participant belongs to a game session and a user', function () {
    $user = User::factory()->create();
    $participant = GameParticipant::factory()->create([
        'user_id' => $user->id,
        'result' => GameResult::Win,
    ]);

    expect($participant->user->is($user))->toBeTrue()
        ->and($participant->gameSession)->not->toBeNull()
        ->and($participant->result)->toBe(GameResult::Win);
});

test('a guest participant has no user', function () {
    $participant = GameParticipant::factory()->guest()->create();

    expect($participant->user_id)->toBeNull()
        ->and($participant->guest_name)->not->toBeNull();
});

test('deleting a user does not delete their game history', function () {
    $user = User::factory()->create();
    $participant = GameParticipant::factory()->create(['user_id' => $user->id]);

    $user->delete();

    expect($participant->fresh())
        ->not->toBeNull()
        ->user_id->toBeNull();
});
