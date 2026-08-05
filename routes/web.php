<?php

use App\Enums\GameType;
use App\Models\GameSession;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::livewire('/about', 'pages::about')->name('about');

Route::livewire('/dashboard', 'pages::dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::livewire('/profile', 'pages::profile')
    ->middleware(['auth', 'verified'])
    ->name('profile');

// Registered before /profile/{username} below, which would otherwise treat
// "boggle" as a username and swallow both these routes -- Laravel matches
// routes in registration order, and {username} is a single wildcard segment
// that matches "/profile/boggle" just as readily as a real username.
Route::livewire('/profile/boggle', 'pages::profile.boggle.index')
    ->middleware(['auth', 'verified'])
    ->name('profile.boggle');

Route::livewire('/profile/boggle/{gameParticipant}', 'pages::profile.boggle.show')
    ->middleware(['auth', 'verified'])
    ->name('profile.boggle.show');

// Same registration-order reasoning as /profile/boggle above.
Route::livewire('/profile/yahtzee', 'pages::profile.yahtzee.index')
    ->middleware(['auth', 'verified'])
    ->name('profile.yahtzee');

Route::livewire('/profile/yahtzee/{gameParticipant}', 'pages::profile.yahtzee.show')
    ->middleware(['auth', 'verified'])
    ->name('profile.yahtzee.show');

// Same registration-order reasoning as /profile/boggle and /profile/yahtzee
// above.
Route::livewire('/profile/family-feud', 'pages::profile.family-feud.index')
    ->middleware(['auth', 'verified'])
    ->name('profile.family-feud');

Route::livewire('/profile/family-feud/{gameParticipant}', 'pages::profile.family-feud.show')
    ->middleware(['auth', 'verified'])
    ->name('profile.family-feud.show');

// Same registration-order reasoning as /profile/boggle etc. above.
Route::livewire('/profile/farkle', 'pages::profile.farkle.index')
    ->middleware(['auth', 'verified'])
    ->name('profile.farkle');

Route::livewire('/profile/farkle/{gameParticipant}', 'pages::profile.farkle.show')
    ->middleware(['auth', 'verified'])
    ->name('profile.farkle.show');

// Same registration-order reasoning as /profile/boggle etc. above.
Route::livewire('/profile/games', 'pages::profile.games.index')
    ->middleware(['auth', 'verified'])
    ->name('profile.games');

// Public profile -- distinct route from /profile above (that's the
// authenticated user's own editable profile); this is a read-only view of
// anyone's, gated by their own is_public flag rather than auth middleware.
Route::livewire('/profile/{username}', 'pages::profile.show')->name('profile.show');

Route::livewire('/boggle', 'pages::boggle.lobby')->name('boggle.lobby');
Route::livewire('/boggle/play', 'pages::boggle.play')->name('boggle.play');

// Shared-screen, display-only variant (no word entry, no accounts, no
// server-tracked score) -- mirrors legacy/boggle-static.html exactly.
Route::livewire('/boggle/group', 'pages::boggle.group')->name('boggle.group');

// No auth middleware: presence channels (see routes/channels.php) always
// require an authenticated guard user, so pages::boggle.room's mount()
// auto-provisions (and logs in) a throwaway guest account for anyone who
// arrives here without one -- see User::createGuest(). Neither joining nor
// hosting (boggle-lobby's createRoom(), same provisioning) needs a real
// account.
Route::livewire('/boggle/room/{code}', 'pages::boggle.room')
    ->name('boggle.room');

// Async challenge link: replays a completed solo game's exact board via
// its stored seed, so a friend can play it later at their own pace and
// compare scores. Just a redirect (not its own Livewire page) since
// pages::boggle.play already knows how to join an existing session.
Route::get('/boggle/share/{gameSession}', function (GameSession $gameSession) {
    abort_unless($gameSession->type === GameType::Boggle, 404);

    return redirect()->route('boggle.play', [
        'size' => $gameSession->settings['size'],
        'minutes' => $gameSession->settings['minutes'],
        'challenge' => $gameSession->id,
    ]);
})->name('boggle.share');

// Pass-and-play (no room param) mirrors legacy/yahtzee.html exactly: one
// device, everyone takes turns. Also handles the online room's own
// gameplay once started, via the #[Url] $room param -- see
// pages::yahtzee.room below for how a room actually gets there.
Route::livewire('/yahtzee', 'pages::yahtzee.play')->name('yahtzee.play');

// Dice-only, no scorecard -- mirrors legacy/yahtzee-roller.html exactly.
Route::livewire('/yahtzee/roller', 'pages::yahtzee.roller')->name('yahtzee.roller');

// No auth middleware -- same reasoning as boggle.room: pages::yahtzee.room's
// mount() auto-provisions a guest account for anyone arriving without one,
// and hosting (creating the Room, from pages::yahtzee.play's own setup
// screen) uses the same provisioning rather than requiring a real account.
Route::livewire('/yahtzee/room/{code}', 'pages::yahtzee.room')
    ->name('yahtzee.room');

// Single shared screen, no host/board split (see docs/CHANGELOG.md's Phase 6
// notes) -- the LLM is the judge, not a person, so there's no hidden
// information a separate host view would need to protect. One device, same
// as Yahtzee. Also handles the online room's own gameplay once started, via
// the #[Url] $room param -- see pages::family-feud.room below.
Route::livewire('/family-feud', 'pages::family-feud.play')->name('family-feud.play');

// No auth middleware -- same reasoning as boggle.room/yahtzee.room:
// pages::family-feud.room's mount() auto-provisions a guest account for
// anyone arriving without one, and hosting uses the same provisioning.
Route::livewire('/family-feud/room/{code}', 'pages::family-feud.room')
    ->name('family-feud.room');

// Pass-and-play (no room param) or the online room's own gameplay via the
// #[Url] $room param -- mirrors pages::yahtzee.play exactly. See
// pages::farkle.room below for how a room actually gets there.
Route::livewire('/farkle', 'pages::farkle.play')->name('farkle.play');

// No auth middleware -- same reasoning as boggle.room/yahtzee.room:
// pages::farkle.room's mount() auto-provisions a guest account for anyone
// arriving without one, and hosting (pages::farkle.play's own setup
// screen) uses the same provisioning.
Route::livewire('/farkle/room/{code}', 'pages::farkle.room')
    ->name('farkle.room');
