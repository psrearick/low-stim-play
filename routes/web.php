<?php

use App\Analytics\Http\Controllers\DoctorController;
use App\Enums\GameType;
use App\Models\GameSession;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// See App\Analytics\Console\Commands\AnalyticsDoctor's docblock -- the
// `analytics:doctor` artisan command fetches this over a real HTTP
// request rather than inspecting the CLI process's own environment.
Route::get('/analytics/doctor', DoctorController::class)->name('analytics.doctor');

Route::livewire('/analytics', 'pages::analytics')
    ->middleware(config('analytics.dashboard.middleware'))
    ->name('analytics.dashboard');

Route::livewire('/about', 'pages::about')->name('about');

Route::livewire('/privacy', 'pages::privacy')->name('privacy');

Route::livewire('/dashboard', 'pages::dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::livewire('/profile', 'pages::profile')
    ->middleware(['auth', 'verified'])
    ->name('profile');

Route::livewire('/friends', 'pages::friends')
    ->middleware(['auth', 'verified'])
    ->name('friends');

// Registered before /profile/{username} below, which would otherwise treat
// "word-grid" as a username and swallow both these routes -- Laravel matches
// routes in registration order, and {username} is a single wildcard segment
// that matches "/profile/word-grid" just as readily as a real username.
Route::livewire('/profile/word-grid', 'pages::profile.boggle.index')
    ->middleware(['auth', 'verified'])
    ->name('profile.boggle');

Route::livewire('/profile/word-grid/{gameParticipant}', 'pages::profile.boggle.show')
    ->middleware(['auth', 'verified'])
    ->name('profile.boggle.show');

// Same registration-order reasoning as /profile/word-grid above.
Route::livewire('/profile/yacht', 'pages::profile.yahtzee.index')
    ->middleware(['auth', 'verified'])
    ->name('profile.yahtzee');

Route::livewire('/profile/yacht/{gameParticipant}', 'pages::profile.yahtzee.show')
    ->middleware(['auth', 'verified'])
    ->name('profile.yahtzee.show');

// Same registration-order reasoning as /profile/word-grid and /profile/yacht
// above.
Route::livewire('/profile/survey-says', 'pages::profile.family-feud.index')
    ->middleware(['auth', 'verified'])
    ->name('profile.family-feud');

Route::livewire('/profile/survey-says/{gameParticipant}', 'pages::profile.family-feud.show')
    ->middleware(['auth', 'verified'])
    ->name('profile.family-feud.show');

// Same registration-order reasoning as /profile/word-grid etc. above.
Route::livewire('/profile/10000', 'pages::profile.farkle.index')
    ->middleware(['auth', 'verified'])
    ->name('profile.farkle');

Route::livewire('/profile/10000/{gameParticipant}', 'pages::profile.farkle.show')
    ->middleware(['auth', 'verified'])
    ->name('profile.farkle.show');

// Same registration-order reasoning as /profile/word-grid etc. above.
Route::livewire('/profile/apple-match', 'pages::profile.apple-match.index')
    ->middleware(['auth', 'verified'])
    ->name('profile.apple-match');

Route::livewire('/profile/apple-match/{gameParticipant}', 'pages::profile.apple-match.show')
    ->middleware(['auth', 'verified'])
    ->name('profile.apple-match.show');

// Same registration-order reasoning as /profile/word-grid etc. above.
Route::livewire('/profile/clear-the-deck', 'pages::profile.clear-the-deck.index')
    ->middleware(['auth', 'verified'])
    ->name('profile.clear-the-deck');

Route::livewire('/profile/clear-the-deck/{gameParticipant}', 'pages::profile.clear-the-deck.show')
    ->middleware(['auth', 'verified'])
    ->name('profile.clear-the-deck.show');

// Same registration-order reasoning as /profile/word-grid etc. above.
Route::livewire('/profile/games', 'pages::profile.games.index')
    ->middleware(['auth', 'verified'])
    ->name('profile.games');

// Public profile -- distinct route from /profile above (that's the
// authenticated user's own editable profile); this is a read-only view of
// anyone's, gated by their own is_public flag rather than auth middleware.
Route::livewire('/profile/{username}', 'pages::profile.show')->name('profile.show');

Route::livewire('/word-grid', 'pages::boggle.lobby')->name('boggle.lobby');
Route::livewire('/word-grid/play', 'pages::boggle.play')->name('boggle.play');

// Shared-screen, display-only variant (no word entry, no accounts, no
// server-tracked score) -- mirrors legacy/boggle-static.html exactly.
Route::livewire('/word-grid/group', 'pages::boggle.group')->name('boggle.group');

// No auth middleware: presence channels (see routes/channels.php) always
// require an authenticated guard user, so pages::boggle.room's mount()
// auto-provisions (and logs in) a throwaway guest account for anyone who
// arrives here without one -- see User::createGuest(). Neither joining nor
// hosting (boggle-lobby's createRoom(), same provisioning) needs a real
// account.
Route::livewire('/word-grid/room/{code}', 'pages::boggle.room')
    ->name('boggle.room');

// Async challenge link: replays a completed solo game's exact board via
// its stored seed, so a friend can play it later at their own pace and
// compare scores. Just a redirect (not its own Livewire page) since
// pages::boggle.play already knows how to join an existing session.
Route::get('/word-grid/share/{gameSession}', function (GameSession $gameSession) {
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
Route::livewire('/yacht', 'pages::yahtzee.play')->name('yahtzee.play');

// Dice-only, no scorecard -- mirrors legacy/yahtzee-roller.html exactly.
Route::livewire('/yacht/roller', 'pages::yahtzee.roller')->name('yahtzee.roller');

// No auth middleware -- same reasoning as boggle.room: pages::yahtzee.room's
// mount() auto-provisions a guest account for anyone arriving without one,
// and hosting (creating the Room, from pages::yahtzee.play's own setup
// screen) uses the same provisioning rather than requiring a real account.
Route::livewire('/yacht/room/{code}', 'pages::yahtzee.room')
    ->name('yahtzee.room');

// Single shared screen, no host/board split (see docs/CHANGELOG.md's Phase 6
// notes) -- the LLM is the judge, not a person, so there's no hidden
// information a separate host view would need to protect. One device, same
// as Yahtzee. Also handles the online room's own gameplay once started, via
// the #[Url] $room param -- see pages::family-feud.room below.
Route::livewire('/survey-says', 'pages::family-feud.play')->name('family-feud.play');

// No auth middleware -- same reasoning as boggle.room/yahtzee.room:
// pages::family-feud.room's mount() auto-provisions a guest account for
// anyone arriving without one, and hosting uses the same provisioning.
Route::livewire('/survey-says/room/{code}', 'pages::family-feud.room')
    ->name('family-feud.room');

// Pass-and-play (no room param) or the online room's own gameplay via the
// #[Url] $room param -- mirrors pages::yahtzee.play exactly. See
// pages::farkle.room below for how a room actually gets there.
Route::livewire('/10000', 'pages::farkle.play')->name('farkle.play');

// No auth middleware -- same reasoning as boggle.room/yahtzee.room:
// pages::farkle.room's mount() auto-provisions a guest account for anyone
// arriving without one, and hosting (pages::farkle.play's own setup
// screen) uses the same provisioning.
Route::livewire('/10000/room/{code}', 'pages::farkle.room')
    ->name('farkle.room');

// Online rooms only, no pass-and-play (see docs/specs/apple-match.md
// section 1) -- reachable bare (no #[Url] $room) from the homepage card,
// which shows a "play in a room you host" list plus a pointer to the
// navbar's Rooms menu, rather than a local setup form like every other
// game's own bare .play route.
Route::livewire('/apple-match', 'pages::apple-match.play')->name('apple-match.play');

// No auth middleware -- same reasoning as boggle.room/yahtzee.room/etc.:
// pages::apple-match.room's mount() auto-provisions a guest account for
// anyone arriving without one.
Route::livewire('/apple-match/room/{code}', 'pages::apple-match.room')
    ->name('apple-match.room');

// Online rooms only, no pass-and-play (see docs/specs/clear-the-deck.md
// section 1) -- same reasoning and bare-visit behavior as /apple-match
// above.
Route::livewire('/clear-the-deck', 'pages::clear-the-deck.play')->name('clear-the-deck.play');

// No auth middleware -- same reasoning as boggle.room/yahtzee.room/etc.:
// pages::clear-the-deck.room's mount() auto-provisions a guest account for
// anyone arriving without one.
Route::livewire('/clear-the-deck/room/{code}', 'pages::clear-the-deck.room')
    ->name('clear-the-deck.room');

// The room hub -- game-agnostic, unlike every /{game}/room/{code} above
// (each of those is specifically the seat-assignment/start screen for
// *that* game). This is where a room's own code/member list/host controls
// live, and where the navbar's Rooms menu links each room to. No auth
// middleware, same reasoning as every other room route: mount() auto-
// provisions a guest for a first-time direct visitor.
Route::livewire('/room/{code}', 'pages::room.show')->name('room.show');
