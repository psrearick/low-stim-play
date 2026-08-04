<?php

use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to login', function () {
    $this->get('/profile')->assertRedirect('/login');
});

test('profile page renders for authenticated users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/profile')
        ->assertSeeLivewire('pages::profile');
});

test('users can update their name, username, and visibility', function () {
    $user = User::factory()->create(['username' => null, 'is_public' => true]);

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('name', 'Updated Name')
        ->set('username', 'updated_username')
        ->set('is_public', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh())
        ->name->toBe('Updated Name')
        ->username->toBe('updated_username')
        ->is_public->toBeFalse();
});

test('username must be unique', function () {
    User::factory()->create(['username' => 'taken']);
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('username', 'taken')
        ->call('save')
        ->assertHasErrors(['username']);
});

test('clearing stats deletes the user\'s own game participant rows only', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $session = GameSession::factory()->create();

    GameParticipant::factory()->count(3)->create(['user_id' => $user->id]);
    // Same shared session as one of the user's own rows -- clearing must
    // never touch another player's own history, even for a game they
    // both played together.
    $ownRowOnSharedSession = GameParticipant::factory()->create(['user_id' => $user->id, 'game_session_id' => $session->id]);
    $otherPlayersRow = GameParticipant::factory()->create(['user_id' => $otherUser->id, 'game_session_id' => $session->id]);

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->call('clearStats')
        ->assertSet('statsCleared', true);

    expect(GameParticipant::where('user_id', $user->id)->count())->toBe(0);
    expect(GameParticipant::find($otherPlayersRow->id))->not->toBeNull();
    expect(GameParticipant::find($ownRowOnSharedSession->id))->toBeNull();
    expect(GameSession::find($session->id))->not->toBeNull();
});

test('deleting the account removes the user, logs them out, and redirects home', function () {
    $user = User::factory()->create();
    $userId = $user->id;

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->call('deleteAccount')
        ->assertRedirect('/');

    expect(User::find($userId))->toBeNull();
    $this->assertGuest();
});

test('deleting the account cascades passkeys and hosted rooms but only nulls out shared game participant rows', function () {
    $user = User::factory()->create();
    $user->passkeys()->create([
        'name' => 'Test device',
        'credential_id' => 'credential-id',
        'credential' => ['type' => 'public-key'],
    ]);
    $hostedRoom = Room::factory()->create(['host_user_id' => $user->id]);
    $ownParticipation = GameParticipant::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)->test('pages::profile')->call('deleteAccount');

    expect($user->passkeys()->count())->toBe(0);
    expect(Room::find($hostedRoom->id))->toBeNull();
    expect(GameParticipant::find($ownParticipation->id)->user_id)->toBeNull();
});

test('registering again with a deleted account\'s email creates a genuinely new account', function () {
    $user = User::factory()->create(['email' => 'reused@example.com']);
    $originalId = $user->id;

    Livewire::actingAs($user)->test('pages::profile')->call('deleteAccount');

    $this->post('/register', [
        'name' => 'New Person',
        'email' => 'reused@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect();

    $newUser = User::where('email', 'reused@example.com')->first();

    expect($newUser)->not->toBeNull();
    expect($newUser->id)->not->toBe($originalId);
    $this->assertAuthenticatedAs($newUser);
});

test('the passkey list renders on the profile page', function () {
    $user = User::factory()->create();
    $user->passkeys()->create([
        'name' => 'Test device',
        'credential_id' => 'credential-id',
        'credential' => ['type' => 'public-key'],
    ]);

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->assertSee('Test device');
});
