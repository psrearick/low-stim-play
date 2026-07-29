<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a game session casts type, mode, and settings', function () {
    $session = GameSession::factory()->create([
        'type' => GameType::Boggle,
        'mode' => GameMode::Local,
        'settings' => ['board_size' => 5, 'time_limit' => 180],
    ]);

    $session = $session->fresh();

    expect($session->type)->toBe(GameType::Boggle)
        ->and($session->mode)->toBe(GameMode::Local)
        ->and($session->settings)->toBe(['board_size' => 5, 'time_limit' => 180]);
});

test('a game session has many participants', function () {
    $session = GameSession::factory()->create();
    GameParticipant::factory()->count(3)->create(['game_session_id' => $session->id]);

    expect($session->participants()->count())->toBe(3);
});
