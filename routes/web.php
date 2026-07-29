<?php

use App\Enums\GameType;
use App\Models\GameSession;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/home', 'home')
    ->middleware(['auth', 'verified'])
    ->name('home');

Route::livewire('/profile', 'pages::profile')
    ->middleware(['auth', 'verified'])
    ->name('profile');

Route::livewire('/boggle', 'pages::boggle.lobby')->name('boggle.lobby');
Route::livewire('/boggle/play', 'pages::boggle.play')->name('boggle.play');

// Presence channels (see routes/channels.php) require an authenticated
// user, so online rooms -- unlike solo play -- aren't open to guests.
Route::livewire('/boggle/room/{code}', 'pages::boggle.room')
    ->middleware('auth')
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
