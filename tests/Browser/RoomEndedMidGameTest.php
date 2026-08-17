<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\YahtzeeCategory;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regression coverage for docs/concerns.md: ending a room while a game was
 * in progress briefly flashed a raw 500 error page on screen. Root cause:
 * Room::delete() (pages::room.show's endRoom()) leaves any other game
 * page's own resolveRoom() (Room::where('code', ...)->firstOrFail()) with
 * nothing to find on its next action, throwing a ModelNotFoundException ->
 * a 404 response Livewire's client had no interceptor for, so its default
 * showHtmlModal() behavior dumped the raw HTML error response into an
 * overlay right on top of the game. Fixed with a global
 * Livewire.interceptRequest() in resources/js/app.js: any failed request
 * (except 422, Livewire's own validation-error status) is redirected home
 * instead of shown.
 *
 * Deletes the Room row directly (rather than driving pages::room.show's
 * own endRoom() through a second browser) to isolate the "an action fails
 * after the fact" path this fix actually covers, independent of whether
 * the RoomEnded broadcast happens to arrive in time -- matches this app's
 * own single-real-browser-plus-DB-seeded-state convention (see
 * YahtzeeRoomBrowserTest.php's docblock).
 */
it('redirects home instead of flashing an error when an action outlives its room', function () {
    $host = User::factory()->create(['password' => bcrypt('password')]);
    $guest = User::factory()->create(['password' => bcrypt('password')]);

    $room = Room::factory()->active()->create(['host_user_id' => $host->id]);

    $emptyScores = array_fill_keys(array_map(fn ($c) => $c->value, YahtzeeCategory::all()), null);

    $session = GameSession::factory()->create([
        'type' => GameType::Yahtzee,
        'mode' => GameMode::Online,
        'settings' => ['player_count' => 2],
        'state' => [
            'seats' => [
                ['seatIndex' => 0, 'name' => $host->name, 'scores' => $emptyScores, 'yahtzeeBonusCount' => 0],
                ['seatIndex' => 1, 'name' => $guest->name, 'scores' => $emptyScores, 'yahtzeeBonusCount' => 0],
            ],
            'currentSeat' => 0,
            'dice' => [],
            'kept' => [],
            'rollsLeft' => 3,
            'gameOver' => false,
        ],
    ]);

    $room->update(['game_session_id' => $session->id]);

    RoomSeat::factory()->create(['room_id' => $room->id, 'seat_index' => 0, 'user_id' => $host->id]);
    RoomSeat::factory()->create(['room_id' => $room->id, 'seat_index' => 1, 'user_id' => $guest->id]);

    $page = visit('/login');
    $page->fill('email', $host->email)->fill('password', 'password')->click('#login-submit');
    $page->navigate('/yacht?room='.$room->code);
    $page->assertSee('Roll');

    Room::where('id', $room->id)->delete();

    $page->click('#rollBtn');
    $page->wait(1);

    $info = $page->script(
        "(() => ({ errorDialog: document.getElementById('livewire-error') !== null, path: location.pathname }))()"
    );

    expect($info['errorDialog'])->toBeFalse();
    expect($info['path'])->toBe('/');
});
