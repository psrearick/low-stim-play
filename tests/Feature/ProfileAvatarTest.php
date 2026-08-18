<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * docs/TODO.md's former #3: `<x-avatar>` used to only ever render a
 * generated initial-letter badge. Uploads go straight to the S3-compatible
 * bucket configured via config/filesystems.php's 's3' disk (Storage::fake()
 * swaps in a real local fake disk transparently, so none of this needs
 * actual bucket credentials to test).
 */
test('a user with no uploaded avatar has a null avatar url', function () {
    $user = User::factory()->create();

    expect($user->avatarUrl())->toBeNull();
});

test('a user with an uploaded avatar has a url built from the s3 disk', function () {
    $user = User::factory()->create(['avatar_path' => 'avatars/example.jpg']);

    expect($user->avatarUrl())->toBe(Storage::disk('s3')->url('avatars/example.jpg'));
});

test('uploading an avatar stores it on the s3 disk and records the path', function () {
    Storage::fake('s3');
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('avatarUpload', UploadedFile::fake()->image('avatar.jpg'))
        ->call('uploadAvatar')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->avatar_path)->not->toBeNull();
    Storage::disk('s3')->assertExists($user->avatar_path);
});

test('uploading a new avatar deletes the previous one from the s3 disk', function () {
    Storage::fake('s3');
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('avatarUpload', UploadedFile::fake()->image('first.jpg'))
        ->call('uploadAvatar');

    $firstPath = $user->fresh()->avatar_path;
    Storage::disk('s3')->assertExists($firstPath);

    $component->set('avatarUpload', UploadedFile::fake()->image('second.jpg'))
        ->call('uploadAvatar');

    $secondPath = $user->fresh()->avatar_path;

    expect($secondPath)->not->toBe($firstPath);
    Storage::disk('s3')->assertMissing($firstPath);
    Storage::disk('s3')->assertExists($secondPath);
});

test('a non-image upload is rejected', function () {
    Storage::fake('s3');
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('avatarUpload', UploadedFile::fake()->create('not-an-image.pdf', 100))
        ->assertHasErrors(['avatarUpload' => 'image']);

    expect($user->fresh()->avatar_path)->toBeNull();
});

test('removing an avatar deletes it from the s3 disk and clears the path', function () {
    Storage::fake('s3');
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('avatarUpload', UploadedFile::fake()->image('avatar.jpg'))
        ->call('uploadAvatar');

    $path = $user->fresh()->avatar_path;

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->call('removeAvatar')
        ->assertSet('avatarUrl', null);

    expect($user->fresh()->avatar_path)->toBeNull();
    Storage::disk('s3')->assertMissing($path);
});

test('the dashboard renders an uploaded avatar as an image instead of the initial-letter badge', function () {
    $user = User::factory()->create(['avatar_path' => 'avatars/example.jpg']);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertSee('<img', false)
        ->assertSee(Storage::disk('s3')->url('avatars/example.jpg'), false);
});

test('a public profile page renders an uploaded avatar as an image', function () {
    $user = User::factory()->create(['username' => 'alex', 'avatar_path' => 'avatars/example.jpg', 'is_public' => true]);

    $this->get('/profile/alex')
        ->assertSee('<img', false)
        ->assertSee(Storage::disk('s3')->url('avatars/example.jpg'), false);
});
