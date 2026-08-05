<?php

use App\Models\GameParticipant;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a guest account older than a day is deleted', function () {
    $guest = User::createGuest('Alex');
    $guest->forceFill(['created_at' => now()->subDays(2)])->save();

    $this->artisan('users:prune-guests')->assertSuccessful();

    expect(User::find($guest->id))->toBeNull();
});

test('a guest account less than a day old is kept', function () {
    $guest = User::createGuest('Alex');
    $guest->forceFill(['created_at' => now()->subHours(2)])->save();

    $this->artisan('users:prune-guests')->assertSuccessful();

    expect(User::find($guest->id))->not->toBeNull();
});

test('a real (non-guest) account is never deleted, no matter how old', function () {
    $user = User::factory()->create();
    $user->forceFill(['created_at' => now()->subYear()])->save();

    $this->artisan('users:prune-guests')->assertSuccessful();

    expect(User::find($user->id))->not->toBeNull();
});

test('pruning a guest cascades their passkeys and hosted rooms but only nulls out game participant rows', function () {
    $guest = User::createGuest('Alex');
    $guest->forceFill(['created_at' => now()->subDays(2)])->save();

    $guest->passkeys()->create([
        'name' => 'Test device',
        'credential_id' => 'credential-id',
        'credential' => ['type' => 'public-key'],
    ]);
    $hostedRoom = Room::factory()->create(['host_user_id' => $guest->id]);
    $participation = GameParticipant::factory()->create(['user_id' => $guest->id]);

    $this->artisan('users:prune-guests');

    expect($guest->passkeys()->count())->toBe(0);
    expect(Room::find($hostedRoom->id))->toBeNull();
    expect(GameParticipant::find($participation->id)->user_id)->toBeNull();
});

test('the command reports how many guest accounts it deleted', function () {
    $stale = User::createGuest('Alex');
    $stale->forceFill(['created_at' => now()->subDays(2)])->save();

    User::createGuest('Sam'); // fresh, not deleted
    User::factory()->create(['created_at' => now()->subYear()]); // real account, not deleted

    $this->artisan('users:prune-guests')
        ->expectsOutputToContain('Deleted 1 guest account')
        ->assertSuccessful();
});
