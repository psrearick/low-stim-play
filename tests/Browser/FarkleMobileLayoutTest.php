<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regression coverage for Farkle's layout reports in docs/concerns.md
 * (both the original round and the follow-up round covering the banked
 * dice row's position/grouping, the mobile cutoff, and the scoreboard
 * font size again) -- all CSS/layout bugs that a Livewire-level test
 * can't see, per CLAUDE.md's own testing rules, so each is confirmed
 * against real rendered layout instead of just asserting the markup
 * exists.
 */
it('lets every player row be reached by scrolling the scoreboard on a short mobile viewport', function () {
    // 500px tall -- short enough that #farkle-game's flex chain squeezes
    // .farkle-scoreboard below its natural content height. Before the
    // fix, that squeeze combined with the container's own rounded-corner
    // overflow:hidden silently clipped the lower rows with nothing
    // scrollable to reach them (confirmed live via a real scripted
    // session: scrollHeight/clientHeight both fit the outer document
    // exactly, while the scoreboard's own scrollHeight was taller than
    // its clientHeight).
    $page = visit('/10000')->resize(390, 500);

    // One click at a time, waiting for each Livewire round trip to land
    // before the next -- firing all 6 clicks back to back raced the
    // stepper's own disabled-state re-render in the full suite (though
    // not in isolation), leaving the final player count nondeterministic.
    for ($i = 0; $i < 6; $i++) {
        $page->click('[aria-label="More players"]');
        $page->wait(0.2);
    }

    expect($page->script("document.querySelector('.stepper-value').textContent.trim()"))->toBe('8');

    $page->click('Start Game')->assertSee('Roll');

    $dimensions = $page->script(
        "(() => { const el = document.querySelector('.farkle-scoreboard'); return {scrollHeight: el.scrollHeight, clientHeight: el.clientHeight}; })()"
    );

    expect($dimensions['scrollHeight'])->toBeGreaterThan($dimensions['clientHeight']);

    $page->script('document.querySelector(".farkle-scoreboard").scrollTop = 9999');

    $lastRowVisible = $page->script(
        "(() => { const container = document.querySelector('.farkle-scoreboard'); const spans = container.querySelectorAll('.grid > span'); const last = spans[spans.length - 2]; const c = container.getBoundingClientRect(); const r = last.getBoundingClientRect(); return r.top >= c.top && r.bottom <= c.bottom; })()"
    );

    expect($lastRowVisible)->toBeTrue();
});

it('renders banked dice below the main dice row, one row per roll of up to six', function () {
    $page = visit('/10000')->resize(1280, 900);

    $page->click('Start Game')->assertSee('Roll');

    // A long multi-roll turn (more than 6 dice banked, since hot dice
    // resets the count) used to render as a single row that either
    // wrapped (pushing the roll row down) or scrolled sideways (cutting
    // dice off on mobile) -- docs/concerns.md asked for it to render
    // below the main dice row instead, split into one row per roll of
    // up to six. Set directly via the Livewire component rather than
    // actually playing out a hot-dice streak, which isn't reliably
    // reachable from real random_int() rolls. Looked up by wire:id (via
    // #farkle-game's closest [wire\\:id] ancestor), not
    // window.Livewire.first() -- <livewire:rooms-menu/> now renders
    // earlier in the layout on every page, so .first() no longer
    // reliably returns the Farkle page's own component and silently
    // threw a PublicPropertyNotFoundException that hung the whole
    // browser session instead of failing fast.
    $page->script(
        "window.Livewire.find(document.getElementById('farkle-game').closest('[wire\\\\:id]').getAttribute('wire:id')).set('bankedDiceThisTurn', [1,2,3,4,5,6,1,2,3,4,5,6,1,2])"
    );
    $page->wait(0.3);

    $info = $page->script(
        "(() => { const rows = [...document.querySelectorAll('.farkle-banked-row')]; const diceAreaBottom = document.querySelector('.dice-area').getBoundingClientRect().bottom; const firstRowTop = rows[0].getBoundingClientRect().top; return {perRow: rows.map(r => r.querySelectorAll('.die').length), belowDiceArea: firstRowTop > diceAreaBottom, anyOverflow: rows.some(r => r.scrollWidth > r.clientWidth + 1)}; })()"
    );

    expect($info['perRow'])->toBe([6, 6, 2]);
    expect($info['belowDiceArea'])->toBeTrue();
    expect($info['anyOverflow'])->toBeFalse();
});

/**
 * docs/TODO.md's former #20: the same bug already found and fixed in
 * Yahtzee's own .yahtzee-play .row (see YahtzeeRoomBrowserTest's own
 * "short, wide TV-cast viewport" test) was confirmed present in
 * .farkle-play .row too -- a positive flex-grow on the flex: 1 1 auto
 * six-die grid inflated it (and, since dice are aspect-ratio: 1/1,
 * .dice-area's height along with it) well past its intended
 * min(56vw, 42vh, 720px) cap. Confirmed live at 1920x1080 before the fix:
 * .row rendered 1600px wide against a ~454px intended cap.
 */
it('keeps the dice row capped at its intended width on a wide desktop viewport', function () {
    $page = visit('/10000')->resize(1920, 1080);

    $page->click('Start Game')->assertSee('Roll');

    $width = $page->script(
        "document.querySelector('.farkle-play .row').getBoundingClientRect().width"
    );

    // Intended cap is min(56vw, 42vh, 720px) -- at 1920x1080 that's
    // 42vh = 453.6px. Allow a little slack for gap/border rounding while
    // still clearly rejecting the pre-fix ~1600px blowout.
    expect($width)->toBeLessThan(500);
});

it('does not overlap the dice with the roll button in a narrow portrait viewport', function () {
    $page = visit('/10000')->resize(390, 844);

    $page->click('Start Game')->assertSee('Roll');

    $rects = $page->script(
        "(() => { const dice = [...document.querySelectorAll('.dice-area .row .die')].map(el => el.getBoundingClientRect()); const roll = document.querySelector('#rollBtn').getBoundingClientRect(); return {dice, roll}; })()"
    );

    // Every die must be a real, legible square (not squeezed to a
    // sliver by sharing a nowrap row with the Roll button's reserved
    // width) and none of them may overlap the Roll button's own box.
    foreach ($rects['dice'] as $die) {
        expect($die['width'])->toBeGreaterThan(20);

        $overlapsRoll = $die['left'] < $rects['roll']['right']
            && $die['right'] > $rects['roll']['left']
            && $die['top'] < $rects['roll']['bottom']
            && $die['bottom'] > $rects['roll']['top'];

        expect($overlapsRoll)->toBeFalse();
    }
});

it('keeps a full row of six banked dice on screen on a narrow mobile viewport', function () {
    // docs/concerns.md: on mobile the banked-dice row got cut off on the
    // sides instead of showing all six dice. At the shared (desktop-
    // oriented) min(56vw, ...) width this row used before, six dice
    // didn't reliably fit on a real phone; the portrait-only override
    // widens it to match .farkle-play .row's own portrait width.
    $page = visit('/10000')->resize(390, 844);

    $page->click('Start Game')->assertSee('Roll');

    $page->script(
        "window.Livewire.find(document.getElementById('farkle-game').closest('[wire\\\\:id]').getAttribute('wire:id')).set('bankedDiceThisTurn', [1,2,3,4,5,6])"
    );
    $page->wait(0.3);

    $info = $page->script(
        "(() => { const row = document.querySelector('.farkle-banked-row'); const rect = row.getBoundingClientRect(); return {right: rect.right, viewport: window.innerWidth, dieCount: row.querySelectorAll('.die').length, overflow: row.scrollWidth > row.clientWidth + 1}; })()"
    );

    expect($info['dieCount'])->toBe(6);
    expect($info['right'])->toBeLessThanOrEqual($info['viewport']);
    expect($info['overflow'])->toBeFalse();
});

it('keeps every button-bar button reachable and the fullscreen toggle on screen in a crowded online-room Farkle game', function () {
    // docs/concerns.md: "The navbar buttons are too tight on mobile in a
    // farkle game and the full screen button is off the screen, and the
    // end game button is too easy to hit because it is so crowded." An
    // online-room game (as host, mid-turn) is the crowded case: Rooms,
    // Scores, Watch only, End Game, and the fullscreen toggle all render
    // in .button-bar .right at once, alongside Home + Rules in .left.
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id]);
    $session = GameSession::create([
        'type' => GameType::Farkle,
        'mode' => GameMode::Online,
        'settings' => ['player_count' => 1, 'target_score' => 10000, 'with_final_round' => true],
        'state' => [
            'seats' => [['seatIndex' => 0, 'name' => $host->name, 'bankedScore' => 0, 'hasQualified' => false, 'farkleCount' => 0, 'hotDiceCount' => 0, 'bestTurnScore' => 0]],
            'currentSeat' => 0, 'dice' => [], 'selected' => [], 'bankedDiceThisTurn' => [],
            'turnScore' => 0, 'heldDiceCount' => 0, 'targetScore' => 10000, 'withFinalRound' => true,
            'finalRoundActive' => false, 'finalRoundRemaining' => null, 'gameOver' => false, 'message' => null, 'messageType' => null,
        ],
        'started_at' => now(),
    ]);
    $room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);
    RoomSeat::create(['room_id' => $room->id, 'seat_index' => 0, 'user_id' => $host->id]);

    $this->actingAs($host);

    $page = visit('/10000?room='.$room->code)->resize(375, 812);
    $page->assertSee('Roll');

    $info = $page->script(
        "(() => { const viewport = window.innerWidth; const buttons = [...document.querySelectorAll('.button-bar button, .button-bar a')]; const offscreen = buttons.filter(b => { const r = b.getBoundingClientRect(); return r.right > viewport || r.left < 0; }).map(b => b.id || b.textContent.trim()); const fullscreen = document.querySelector('#chrome-toggle-btn').getBoundingClientRect(); return {viewport, offscreen, fullscreenRight: fullscreen.right, fullscreenLeft: fullscreen.left}; })()"
    );

    expect($info['offscreen'])->toBe([]);
    expect($info['fullscreenRight'])->toBeLessThanOrEqual($info['viewport']);
    expect($info['fullscreenLeft'])->toBeGreaterThanOrEqual(0);
});

it('shows the live scoreboard in a larger font on desktop', function () {
    $page = visit('/10000')->resize(1280, 900);

    $page->click('Start Game')->assertSee('Roll');

    $fontSize = $page->script(
        "(() => { const el = document.querySelector('.farkle-scoreboard:not(.farkle-final-scores) .grid > span:nth-child(2)'); return getComputedStyle(el).fontSize; })()"
    );

    // Tailwind's plain text-sm (used unconditionally before this fix) is
    // 14px, and an earlier pass only bumped this to 18px before still
    // being reported too small (docs/concerns.md) -- assert it's now at
    // least as large as that intermediate value, not an exact number, so
    // this doesn't churn every time the clamp/size is tuned further.
    expect((int) $fontSize)->toBeGreaterThan(18);
});

/**
 * docs/TODO.md's former #17: this scoreboard's flat 22px ceiling (raised
 * from the earlier bump, but never revisited after Farkle's own dedicated
 * spectator scoreboard got a real cqh-scaled size reaching up to 36px)
 * left a seated player's own actively-playing view -- e.g. their device
 * screen-mirrored/cast to a TV without toggling "Watch only" -- stuck at
 * the smaller size. Bumped to clamp(18px, 3.2vh, 32px), matched with
 * .farkle-final-scores' own identical clamp so the two stay in sync as
 * they always have. Confirmed live at a real TV resolution (1920x1080)
 * that this actually reaches the new ceiling, not just "bigger than
 * before" at an arbitrary desktop size.
 */
it('reaches its new, larger font ceiling at a real TV resolution', function () {
    $page = visit('/10000')->resize(1920, 1080);

    $page->click('Start Game')->assertSee('Roll');

    $fontSize = $page->script(
        "(() => { const el = document.querySelector('.farkle-scoreboard:not(.farkle-final-scores) .grid > span:nth-child(2)'); return getComputedStyle(el).fontSize; })()"
    );

    expect((int) $fontSize)->toBe(32);
});

it('does not let Roll Again/Bank overlap the dice in mobile landscape', function () {
    // docs/concerns.md: "In Mobile Landscape, the 'Roll Again' button
    // covers the last die on the right." A short landscape viewport puts
    // .dice-area's default nowrap row (dice + controls sharing one row)
    // under real width pressure. Fixed by giving Farkle a dedicated
    // landscape layout (.farkle-game-area/-dice-column, see app.css) that
    // wraps the dice-area the same way the portrait fix already does, so
    // Roll Again/Bank always render on their own line below the dice.
    $page = visit('/10000')->resize(568, 320);

    $page->click('Start Game')->assertSee('Roll');
    $page->click('#rollBtn');
    $page->wait(0.3);

    $info = $page->script(
        "(() => { const dice = [...document.querySelectorAll('.dice-area .row .die')].map(el => el.getBoundingClientRect()); const controls = document.querySelector('.dice-area .controls').getBoundingClientRect(); const anyOverlap = dice.some(die => die.left < controls.right && die.right > controls.left && die.top < controls.bottom && die.bottom > controls.top); const anyOffscreenLeft = [...document.querySelectorAll('.dice-area .row .die, #rollAgainBtn, #bankBtn')].some(el => el.getBoundingClientRect().left < 0); return {anyOverlap, anyOffscreenLeft}; })()"
    );

    expect($info['anyOverlap'])->toBeFalse();
    expect($info['anyOffscreenLeft'])->toBeFalse();
});

it('gives the scoreboard enough height to reach every player in mobile landscape', function () {
    // docs/concerns.md: "In Mobile Landscape, the page does not scroll,
    // so you cannot see all the scores." The scoreboard's own
    // overflow-y: auto (see the earlier short-mobile-viewport fix above)
    // is real, but landscape's own stacked layout only ever gave it a
    // ~90px sliver to work with, an unusably small internal scroll area
    // for more than a couple of players. Fixed by putting the dice
    // column and scoreboard side by side in landscape (.farkle-game-area,
    // see app.css), which gives the scoreboard roughly the full available
    // height instead. Local pass-and-play, not an online room -- a room
    // game's extra button-bar buttons (Scores/Watch only/End Game) run
    // into their own, separate width limit around this viewport size that
    // isn't one of this round's two reported bugs; isolating this test to
    // local play keeps it from coupling to that unrelated behavior.
    $page = visit('/10000')->resize(667, 375);

    for ($i = 0; $i < 6; $i++) {
        $page->click('[aria-label="More players"]');
        $page->wait(0.15);
    }

    $page->click('Start Game')->assertSee('Roll');

    $before = $page->script(
        "(() => { const el = document.querySelector('.farkle-scoreboard'); return {clientHeight: el.clientHeight, scrollable: el.scrollHeight > el.clientHeight}; })()"
    );

    // A pre-fix ~90px sliver is barely one row tall; the side-by-side
    // landscape layout should give it noticeably more room than that.
    expect($before['clientHeight'])->toBeGreaterThan(120);

    $lastRowReachable = $page->script(
        "(() => { const el = document.querySelector('.farkle-scoreboard'); el.scrollTop = 99999; const rows = el.querySelectorAll('.grid > span'); const last = rows[rows.length - 1]; const lastRect = last.getBoundingClientRect(); const elRect = el.getBoundingClientRect(); return lastRect.bottom <= elRect.bottom + 1 && lastRect.top >= elRect.top - 1; })()"
    );

    expect($lastRowReachable)->toBeTrue();
});
