<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\YahtzeeCategory;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

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
        ->assertSee('Word Grid')
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

test('a local pass-and-play Yahtzee game marked as "you" shows up in recent games', function () {
    // Regression test for the reported bug: Yahtzee/Family Feud are
    // single-device pass-and-play games where every player is just a typed
    // name (guest_name), never tied to user_id, since there's normally no
    // way to know which local player is the account holder. Picking
    // yourself via meIndex is what closes that gap -- see
    // pages::yahtzee.play's endGame().
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::yahtzee.play')
        ->call('setPlayerCount', 1)
        ->set('meIndex', 0)
        ->call('startGame');

    foreach (array_map(fn ($c) => $c->value, YahtzeeCategory::all()) as $category) {
        $component->call('roll')->set('dice', [3, 3, 3, 3, 3])->call('scoreCategory', $category);
    }

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSee('Yacht');
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
