<?php

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

test('users can delete a passkey', function () {
    $user = User::factory()->create();
    $passkey = $user->passkeys()->create([
        'name' => 'Test device',
        'credential_id' => 'credential-id',
        'credential' => ['type' => 'public-key'],
    ]);

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->call('deletePasskey', $passkey->id);

    expect($user->passkeys()->count())->toBe(0);
});
