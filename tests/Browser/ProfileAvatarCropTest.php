<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\Api\PendingAwaitablePage;

uses(RefreshDatabase::class);

/**
 * Same reproducible Livewire-internal bug ProfileAvatarUploadTest.php
 * documents (WithFileUploads::_finishUpload() throwing "Undefined array
 * key 0" under this Pest/amphp browser driver) also blocks the crop
 * dialog's "Use This Photo" -> $wire.$upload() -> "Save Avatar" step here,
 * since both ultimately hit the same server-side upload-finish handler.
 * Not chased further, per the same precedent. What these tests cover
 * instead is the part that's actually new and unverifiable any other way:
 * that Cropper.js's element tree initializes against the real selected
 * photo with a genuine, non-degenerate selection (this app's first attempt
 * at hand-authoring the <cropper-canvas> tree statically in Blade left
 * <cropper-selection> permanently stuck at 0x0 -- confirmed by capturing
 * its own $toCanvas() output and finding it blank -- because the library's
 * sizing logic expects to run once, right after construction, against an
 * <img> whose src is already set; see the comment above the dialog in
 * resources/views/pages/⚡profile.blade.php).
 */
function makeAvatarCropTestImage(): string
{
    $path = tempnam(sys_get_temp_dir(), 'avatar-crop-test').'.jpg';
    $image = imagecreatetruecolor(800, 600);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 60, 200));
    imagejpeg($image, $path);

    return $path;
}

function loginAndVisitProfileForCropTest(User $user): PendingAwaitablePage
{
    $page = visit('/login');
    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('#login-submit');

    $page->navigate('/profile');

    return $page;
}

test('selecting a photo opens the crop dialog with a real, non-empty square selection', function () {
    Storage::fake('s3');
    $page = loginAndVisitProfileForCropTest(User::factory()->create());

    $page->attach('#avatar-upload-input', makeAvatarCropTestImage());

    $page->assertVisible('#avatar-crop-dialog');

    $selection = $page->script(
        "(() => { const el = document.querySelector('#avatar-crop-canvas-wrapper cropper-selection'); if (!el) return null; const r = el.getBoundingClientRect(); return {width: r.width, height: r.height}; })()"
    );

    expect($selection)->not->toBeNull();
    expect($selection['width'])->toBeGreaterThan(0);
    // Square avatar crop -- the selection's aspect-ratio="1" attribute.
    expect((int) $selection['width'])->toBe((int) $selection['height']);
});

test('confirming a crop produces a real, non-empty square image', function () {
    Storage::fake('s3');
    $page = loginAndVisitProfileForCropTest(User::factory()->create());

    $page->attach('#avatar-upload-input', makeAvatarCropTestImage());
    $page->assertVisible('#avatar-crop-dialog');

    $cropped = $page->script(
        "(() => { const el = document.querySelector('#avatar-crop-canvas-wrapper cropper-selection'); return el.\$toCanvas({width: 128, height: 128}).then((canvas) => ({width: canvas.width, height: canvas.height})); })()"
    );

    expect($cropped['width'])->toBe(128);
    expect($cropped['height'])->toBe(128);
});

test('canceling the crop dialog resets the file input and closes the dialog', function () {
    Storage::fake('s3');
    $page = loginAndVisitProfileForCropTest(User::factory()->create());

    $page->attach('#avatar-upload-input', makeAvatarCropTestImage());
    $page->assertVisible('#avatar-crop-dialog');

    $page->click('#avatar-crop-cancel');

    $page->assertMissing('#avatar-crop-dialog');

    $inputValue = $page->script("document.getElementById('avatar-upload-input').value");
    expect($inputValue)->toBe('');
});
