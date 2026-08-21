<?php

use App\Models\Friendship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The navbar avatar dropdown (docs/TODO.md's former #24) -- open/close and
 * each of its three links actually navigating/acting, mirroring
 * tests/Browser/RoomsMenuBrowserTest.php's own coverage of the sibling
 * Rooms dropdown it shares its open/close/position JS with
 * (resources/js/app.js's registerNavbarDropdown()).
 */
test('the avatar toggle opens and closes the account menu panel', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/');

    $page->assertMissing('#user-menu-panel:not([hidden])')
        ->click('#user-menu-toggle')
        ->assertVisible('#user-menu-panel');

    $page->click('.heading')
        ->assertMissing('#user-menu-panel:not([hidden])');
});

test('Stats navigates to the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/');
    $page->click('#user-menu-toggle')
        ->click('#user-menu-panel a[href$="/dashboard"]')
        ->wait(0.3);

    $page->assertPathIs('/dashboard');
});

test('Edit Profile navigates to the profile page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/');
    $page->click('#user-menu-toggle')
        ->click('#user-menu-panel a[href$="/profile"]')
        ->wait(0.3);

    $page->assertPathIs('/profile');
});

test('Logout actually logs the user out and returns to the guest homepage', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/');
    $page->click('#user-menu-toggle')
        ->click('#user-menu-logout')
        ->wait(0.3);

    $page->assertPathIs('/')
        ->assertSee('Sign In')
        ->assertMissing('#user-menu-toggle');

    $this->assertGuest();
});

/**
 * The badge is `absolute -top-1 -right-1` over the avatar toggle button
 * (docs/concerns.md's friend-request badge feature) -- a plain assertSee
 * would confirm the markup exists but not that it actually renders as a
 * visible, positioned dot rather than being clipped/hidden by the round
 * avatar it sits on top of, so this checks real visibility instead.
 */
test('the pending friend request badge is visible and positioned over the avatar', function () {
    $user = User::factory()->create();
    $requester = User::factory()->create();
    Friendship::factory()->create(['requester_id' => $requester->id, 'addressee_id' => $user->id]);
    $this->actingAs($user);

    $page = visit('/');

    $page->assertVisible('#user-menu-friend-request-badge');

    $rects = $page->script(<<<'JS'
        (() => {
            const avatar = document.getElementById('user-menu-toggle').getBoundingClientRect();
            const badge = document.getElementById('user-menu-friend-request-badge').getBoundingClientRect();
            return {
                badgeCenterX: badge.left + badge.width / 2,
                badgeCenterY: badge.top + badge.height / 2,
                avatarCenterX: avatar.left + avatar.width / 2,
                avatarCenterY: avatar.top + avatar.height / 2,
            };
        })()
    JS);

    // The badge sits in the avatar's top-right quadrant, not centered over
    // it or floating off to one side.
    expect($rects['badgeCenterX'])->toBeGreaterThan($rects['avatarCenterX'])
        ->and($rects['badgeCenterY'])->toBeLessThan($rects['avatarCenterY']);
});

test('no badge is present when there are no pending friend requests', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    visit('/')->assertMissing('#user-menu-friend-request-badge');
});

test('a guest visitor sees no avatar toggle at all', function () {
    visit('/')->assertMissing('#user-menu-toggle');
});

/**
 * The avatar used to be a fixed 36px circle (Tailwind's own w-9/h-9),
 * which happened not to match the Rooms button's own real height -- a
 * plain text button's height is font-size/padding-driven (see app.css's
 * clamp()-based `button` rule), not a fixed px value, so a fixed avatar
 * size could only ever match it by coincidence at one specific viewport
 * width. Fixed by stretching the toggle to the row's own height (.right
 * has no align-items override, so its default -- stretch -- already
 * sizes every child to the tallest sibling) and using aspect-square so
 * width follows whatever that stretched height turns out to be, rather
 * than hand-picking a size class per breakpoint.
 */
test('the avatar toggle is the same height as the Rooms button, with real space between them', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/');

    $rects = $page->script(<<<'JS'
        (() => {
            const room = document.getElementById('rooms-menu-toggle').getBoundingClientRect();
            const avatar = document.getElementById('user-menu-toggle').getBoundingClientRect();
            return { roomHeight: room.height, avatarHeight: avatar.height, avatarWidth: avatar.width, gap: avatar.left - room.right };
        })()
    JS);

    expect($rects['avatarHeight'])->toEqualWithDelta($rects['roomHeight'], 1.0)
        // Square, not squashed -- aspect-square tracking the stretched height.
        ->and($rects['avatarWidth'])->toEqualWithDelta($rects['avatarHeight'], 1.0)
        // .right's own flex gap is 10px -- this should visibly exceed it.
        ->and($rects['gap'])->toBeGreaterThan(10);
});

/**
 * Real bug, reported by the user after an earlier fix attempt (docs/
 * DEVLOG.md's "Third follow-up") failed to reproduce it despite extensive
 * resize()-based testing across 10+ widths. Root cause: .button-bar
 * button (max-width: 360px) and .button-bar.many-buttons button
 * (max-width: 480px) in app.css are plain, unlayered rules -- higher
 * specificity than Tailwind's single-class `.p-0` utility the avatar
 * toggle relies on to stay padding-free, so on any page with the
 * `manyButtons` slot set (welcome.blade.php always sets it) they silently
 * won and added *horizontal-only* padding (never top/bottom) to
 * #user-menu-toggle at real mobile widths. resources/js/app.js's
 * syncAvatarToggleSize() only ever sets the toggle's outer width/height,
 * never its padding, so the outer button stayed a perfect square while
 * its inner content box -- what <x-avatar>'s w-full/h-full circle
 * actually fills -- was squeezed narrower than tall: a portrait oval.
 *
 * Confirmed live specifically via device emulation (on()->iPhoneSE()),
 * not resize() -- resize() to the exact same 320px width leaves the
 * avatar's padding at 0 in this environment (a real, reproducible
 * discrepancy between the two, not yet root-caused), which is almost
 * certainly why the original investigation's resize()-only sweep never
 * saw this. Use device emulation for any future check of this class of
 * bug rather than assuming resize() alone is equivalent.
 */
test('the avatar stays circular, not oval, on a real mobile viewport', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/')->on()->iPhoneSE();

    $rect = $page->script(<<<'JS'
        document.querySelector('#user-menu-toggle > *').getBoundingClientRect()
    JS);

    expect($rect['width'])->toEqualWithDelta($rect['height'], 1.0);
});

test('the avatar toggle is always the last item in the navbar, even on a page with its own game buttons', function () {
    // Word Grid's play page adds both a teleported #game-actions button
    // (End Game) and the fullscreen toggle to .right -- previously the
    // avatar rendered *before* those, so its position shifted from page
    // to page depending on how many extra buttons a given game added.
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/word-grid/play');

    $rightChildrenCount = (int) $page->script("document.querySelector('.button-bar .right').children.length");
    expect($rightChildrenCount)->toBeGreaterThan(2); // Rooms, End Game, fullscreen toggle, avatar

    $lastChildContainsAvatar = $page->script("!!document.querySelector('.button-bar .right').lastElementChild.querySelector('#user-menu-toggle')");
    expect($lastChildContainsAvatar)->toBeTrue();
});

test('the dropdown panel stays fully on-screen on a narrow mobile viewport', function () {
    // .button-bar .right has a real, documented history of pushing a
    // fixed right-0 dropdown panel off-screen once it wraps below 900px
    // (docs/DEVLOG.md's Room system section, originally caught for the
    // Rooms menu) -- confirming the shared registerNavbarDropdown()
    // positioning logic still holds for this second consumer too.
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/')->resize(390, 844);

    $page->click('#user-menu-toggle')->wait(0.3);

    $rect = $page->script('document.getElementById("user-menu-panel").getBoundingClientRect()');

    expect($rect['left'])->toBeGreaterThanOrEqual(0)
        ->and($rect['right'])->toBeLessThanOrEqual(390);
});
