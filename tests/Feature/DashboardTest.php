<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests are redirected to login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('an authenticated user sees their own stats', function () {
    $user = User::factory()->create(['username' => 'alex']);
    $session = GameSession::factory()->create(['type' => GameType::Boggle, 'mode' => GameMode::Solo]);
    GameParticipant::factory()->create(['game_session_id' => $session->id, 'user_id' => $user->id, 'score' => 42]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSeeLivewire('pages::dashboard')
        ->assertSee('Boggle')
        ->assertSee('42', escape: false);
});

test('the dashboard shows a link into the boggle stats page once the user has played one', function () {
    $user = User::factory()->create();
    $session = GameSession::factory()->create(['type' => GameType::Boggle, 'mode' => GameMode::Solo]);
    GameParticipant::factory()->create(['game_session_id' => $session->id, 'user_id' => $user->id]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSee(route('profile.boggle'), escape: false);
});

test('a user without a username is prompted to choose one instead of linking to a public profile', function () {
    $user = User::factory()->create(['username' => null]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSee('Choose a username');
});

test('logging in redirects to the dashboard, and the redirect target loads', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/dashboard');
});
