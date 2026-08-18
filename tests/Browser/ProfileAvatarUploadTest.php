<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * docs/TODO.md's former #3: the Feature-level tests in ProfileAvatarTest.php
 * already cover the actual upload/validation/storage logic end to end (the
 * part a Livewire test can see) -- driving a real <input type="file">
 * through Playwright's attach() to exercise that same flow live turned out
 * to hit a genuine, reproducible Livewire-internal bug in this environment
 * (WithFileUploads::_finishUpload() throwing "Undefined array key 0",
 * unrelated to any app code -- this app has no other precedent anywhere of
 * testing a real file input, so there was nothing to compare against). Not
 * chased further, per CLAUDE.md's own guidance on browser tool calls that
 * keep failing after several attempts. What a real browser test *can* still
 * usefully check without touching that flow is the one thing a Feature
 * test genuinely can't see: whether the rendered <img> is an actual
 * visible, correctly-shaped image once avatar_path is set, not just
 * present in the HTML string.
 */
test('an uploaded avatar renders as a real, visible round image on the profile page', function () {
    $user = User::factory()->create(['avatar_path' => 'avatars/example.jpg']);

    $page = visit('/login');
    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('#login-submit');

    $page->navigate('/profile');

    $info = $page->script(
        "(() => { const img = document.querySelector('img[alt$=\"\\'s avatar\"]'); if (!img) return null; const style = getComputedStyle(img); const rect = img.getBoundingClientRect(); return {borderRadius: style.borderRadius, objectFit: style.objectFit, width: rect.width, height: rect.height, visible: rect.width > 0 && rect.height > 0}; })()"
    );

    expect($info)->not->toBeNull();
    expect($info['visible'])->toBeTrue();
    expect($info['objectFit'])->toBe('cover');
    // A real pixel radius, not "0px" -- confirms .rounded-full's border-radius
    // actually applied to the <img> (not just the fallback initial-letter <div>).
    expect((int) $info['borderRadius'])->toBeGreaterThan(0);
});
