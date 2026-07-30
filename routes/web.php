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
// arrives here without one -- see User::createGuest(). Joining a room
// doesn't need a real account; hosting one (boggle-lobby's createRoom())
// still does.
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

// Pass-and-play only for now (no login, no accounts, no Room) -- mirrors
// legacy/yahtzee.html exactly: one device, everyone takes turns. Online
// play (per docs/plan.md) can reuse the Room/GameLobby infrastructure
// Boggle's online rooms already validated, if wanted later.
Route::livewire('/yahtzee', 'pages::yahtzee.play')->name('yahtzee.play');

// Dice-only, no scorecard -- mirrors legacy/yahtzee-roller.html exactly.
Route::livewire('/yahtzee/roller', 'pages::yahtzee.roller')->name('yahtzee.roller');

// Single shared screen, no host/board split (see docs/progress.md notes) --
// the LLM is the judge, not a person, so there's no hidden information a
// separate host view would need to protect. One device, same as Yahtzee.
Route::livewire('/family-feud', 'pages::family-feud.play')->name('family-feud.play');
