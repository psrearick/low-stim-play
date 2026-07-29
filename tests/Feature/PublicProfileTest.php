<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('an unknown username 404s', function () {
    $this->get('/profile/nobody-with-this-name')->assertNotFound();
});

test('a public profile shows the user\'s stats to anyone, including guests', function () {
    $user = User::factory()->create(['username' => 'alex', 'is_public' => true]);
    $session = GameSession::factory()->create(['type' => GameType::Yahtzee, 'mode' => GameMode::Local]);
    GameParticipant::factory()->create(['game_session_id' => $session->id, 'user_id' => $user->id, 'score' => 88]);

    $this->get('/profile/alex')
        ->assertOk()
        ->assertSee($user->name)
        ->assertSee('Yahtzee')
        ->assertSee('88', escape: false);
});

test('a private profile hides stats from other users', function () {
    $owner = User::factory()->create(['username' => 'private-alex', 'is_public' => false]);
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->get('/profile/private-alex')
        ->assertOk()
        ->assertSee('This profile is private');
});

test('a private profile is still visible to its own owner', function () {
    $owner = User::factory()->create(['username' => 'private-alex', 'is_public' => false]);
    $session = GameSession::factory()->create(['type' => GameType::Boggle, 'mode' => GameMode::Solo]);
    GameParticipant::factory()->create(['game_session_id' => $session->id, 'user_id' => $owner->id, 'score' => 15]);

    $this->actingAs($owner)
        ->get('/profile/private-alex')
        ->assertOk()
        ->assertDontSee('This profile is private')
        ->assertSee('Boggle');
});

test('a private profile is hidden from guests', function () {
    User::factory()->create(['username' => 'private-alex', 'is_public' => false]);

    $this->get('/profile/private-alex')
        ->assertOk()
        ->assertSee('This profile is private');
});
