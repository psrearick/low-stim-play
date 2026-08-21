<?php

use App\Models\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a daily link produces a deterministic puzzle regardless of who opens it', function () {
    $date = now()->startOfDay()->toDateString();

    $first = Livewire::test('pages::quartiles.play', ['dailyDate' => $date]);
    $second = Livewire::test('pages::quartiles.play', ['dailyDate' => $date]);

    expect($first->get('tiles'))->toBe($second->get('tiles'))
        ->and($first->get('groups'))->toBe($second->get('groups'));
});

test('a daily puzzle shows an in-play badge naming it as such, unlike a random game', function () {
    $date = now()->startOfDay()->toDateString();

    Livewire::test('pages::quartiles.play', ['dailyDate' => $date])
        ->assertSee('Daily Puzzle')
        ->assertSee(now()->startOfDay()->format('M j, Y'));

    Livewire::test('pages::quartiles.play', ['seed' => 1])
        ->assertDontSee('Daily Puzzle');
});

test("a signed-in user gets an early GameSession for today's daily, tagged is_daily", function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $date = now()->startOfDay()->toDateString();
    $component = Livewire::test('pages::quartiles.play', ['dailyDate' => $date]);

    $session = GameSession::sole();
    expect($session->is_daily)->toBeTrue()
        ->and($session->daily_date)->toBe($date)
        ->and($session->ended_at)->toBeNull()
        ->and($component->get('gameSessionId'))->toBe($session->id);
});

test('reloading a finished daily restores the stored result instead of a fresh board', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $date = now()->startOfDay()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::quartiles.play', ['dailyDate' => $date])
        ->set('foundWords', ['owl' => 5])
        ->set('score', 5)
        ->call('endGame');

    $resumed = Livewire::actingAs($user)->test('pages::quartiles.play', ['dailyDate' => $date]);

    expect($resumed->get('ended'))->toBeTrue()
        ->and($resumed->get('dailyAlreadyDone'))->toBeTrue()
        ->and($resumed->get('score'))->toBe(5)
        ->and($resumed->get('foundWords'))->toBe(['owl' => 5]);

    expect(GameSession::count())->toBe(1);
});

test('reloading an in-progress daily resumes found words and score', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $date = now()->startOfDay()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::quartiles.play', ['dailyDate' => $date])
        ->set('foundWords', ['owl' => 5])
        ->set('score', 5)
        ->call('shuffleTiles'); // persists state without ending

    $resumed = Livewire::actingAs($user)->test('pages::quartiles.play', ['dailyDate' => $date]);

    expect($resumed->get('ended'))->toBeFalse()
        ->and($resumed->get('dailyAlreadyDone'))->toBeFalse()
        ->and($resumed->get('score'))->toBe(5)
        ->and($resumed->get('foundWords'))->toBe(['owl' => 5]);
});

/**
 * Regression coverage: resumeInProgressGame()/startNewSession() used to
 * scope "this user's one in-progress row" by (user_id, type, ended_at)
 * only -- with dailies added, a signed-in user can legitimately have two
 * separate in-progress rows at once (their ordinary random game and
 * today's daily). Without the is_daily scoping fix, starting one would
 * silently hijack/overwrite the other's row.
 */
test('an in-progress random game and an in-progress daily coexist without clobbering each other', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $randomComponent = Livewire::actingAs($user)->test('pages::quartiles.play');
    $randomSessionId = $randomComponent->get('gameSessionId');

    $date = now()->startOfDay()->toDateString();
    $dailyComponent = Livewire::actingAs($user)->test('pages::quartiles.play', ['dailyDate' => $date]);
    $dailySessionId = $dailyComponent->get('gameSessionId');

    expect(GameSession::count())->toBe(2)
        ->and($randomSessionId)->not->toBe($dailySessionId);

    // Revisiting the plain random page must still resume the random
    // session, not the daily one (or vice versa).
    $resumedRandom = Livewire::actingAs($user)->test('pages::quartiles.play');
    expect($resumedRandom->get('gameSessionId'))->toBe($randomSessionId);

    $resumedDaily = Livewire::actingAs($user)->test('pages::quartiles.play', ['dailyDate' => $date]);
    expect($resumedDaily->get('gameSessionId'))->toBe($dailySessionId);
});

test('a past daily date is always freely replayable, with no lock and no early session row', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $pastDate = now()->subDays(3)->startOfDay()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::quartiles.play', ['dailyDate' => $pastDate])
        ->call('endGame');

    expect(GameSession::sole()->is_daily)->toBeFalse();

    // Replaying the same past day again starts a genuinely fresh attempt,
    // not a restore of the just-finished one.
    $replay = Livewire::actingAs($user)->test('pages::quartiles.play', ['dailyDate' => $pastDate]);
    expect($replay->get('ended'))->toBeFalse();
});

test('a guest gets no lock and no early session row for a daily, even for today', function () {
    $date = now()->startOfDay()->toDateString();

    Livewire::test('pages::quartiles.play', ['dailyDate' => $date]);

    expect(GameSession::count())->toBe(0);
});

test("today's daily New Game button links to the archive instead of offering a reroll", function () {
    $date = now()->startOfDay()->toDateString();

    $component = Livewire::test('pages::quartiles.play', ['dailyDate' => $date])->call('endGame');

    $component->assertSee(route('daily-puzzles'), false)
        ->assertSee('More Daily Puzzles');
});

test('a finished daily shows the come-back-tomorrow countdown instead of a share link', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $date = now()->startOfDay()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::quartiles.play', ['dailyDate' => $date])
        ->call('endGame');

    $resumed = Livewire::actingAs($user)->test('pages::quartiles.play', ['dailyDate' => $date]);

    $resumed->assertSee('Next daily puzzle in')
        ->assertDontSee(route('quartiles.play', ['seed' => $resumed->get('resolvedSeed')]));
});
