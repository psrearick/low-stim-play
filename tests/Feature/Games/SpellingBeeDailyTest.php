<?php

use App\Models\GameSession;
use App\Models\User;
use App\Services\SpellingBee\PuzzleGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Since a daily puzzle's actual letters come from the real dictionary
 * (not a fixture), this re-derives one real answer word for it via the
 * same PuzzleGenerator::wordsFor() the play page's own reveal uses --
 * needed by tests that submit a real scoring word against today's daily.
 *
 * @param  list<string>  $letters
 */
function findRealWord(array $letters, string $center): string
{
    return app(PuzzleGenerator::class)->wordsFor($letters, $center)[0];
}

test('a daily link produces a deterministic puzzle regardless of who opens it', function () {
    $date = now()->startOfDay()->toDateString();

    $first = Livewire::test('pages::spelling-bee.play', ['dailyDate' => $date]);
    $second = Livewire::test('pages::spelling-bee.play', ['dailyDate' => $date]);

    expect($first->get('letters'))->toBe($second->get('letters'))
        ->and($first->get('center'))->toBe($second->get('center'));
});

test('a daily puzzle shows an in-play badge naming it as such, unlike a random game', function () {
    $date = now()->startOfDay()->toDateString();

    Livewire::test('pages::spelling-bee.play', ['dailyDate' => $date])
        ->assertSee('Daily Puzzle')
        ->assertSee(now()->startOfDay()->format('M j, Y'));

    Livewire::test('pages::spelling-bee.play', ['seed' => 1])
        ->assertDontSee('Daily Puzzle');
});

test("a signed-in user gets an early GameSession for today's daily, tagged is_daily", function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $date = now()->startOfDay()->toDateString();
    $component = Livewire::test('pages::spelling-bee.play', ['dailyDate' => $date]);

    $session = GameSession::sole();
    expect($session->is_daily)->toBeTrue()
        ->and($session->daily_date)->toBe($date)
        ->and($session->ended_at)->toBeNull()
        ->and($component->get('gameSessionId'))->toBe($session->id);
});

test('reloading a finished daily restores the stored result instead of a fresh puzzle', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $date = now()->startOfDay()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::spelling-bee.play', ['dailyDate' => $date])
        ->set('foundWords', ['owl' => 5])
        ->set('score', 5)
        ->call('endGame');

    $resumed = Livewire::actingAs($user)->test('pages::spelling-bee.play', ['dailyDate' => $date]);

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

    $component = Livewire::actingAs($user)->test('pages::spelling-bee.play', ['dailyDate' => $date]);
    $letters = $component->get('letters');
    $center = $component->get('center');

    // A real submission (rather than ->set('foundWords', ...) directly)
    // so persistState() -- only ever called from submitWord() -- actually
    // runs, exercising the real resume path instead of state that was
    // never written.
    $word = findRealWord($letters, $center);
    $component->set('currentWord', $word)->call('submitWord');

    expect($component->get('score'))->toBeGreaterThan(0);

    $resumed = Livewire::actingAs($user)->test('pages::spelling-bee.play', ['dailyDate' => $date]);

    expect($resumed->get('ended'))->toBeFalse()
        ->and($resumed->get('dailyAlreadyDone'))->toBeFalse()
        ->and($resumed->get('score'))->toBe($component->get('score'))
        ->and($resumed->get('foundWords'))->toBe($component->get('foundWords'));
});

/**
 * Regression coverage mirroring QuartilesDailyTest's own test of the same
 * name: resumeInProgressGame()/startNewSession() must scope "this user's
 * one in-progress row" by (user_id, type, is_daily, ended_at) so a random
 * game and today's daily can coexist without clobbering each other.
 */
test('an in-progress random game and an in-progress daily coexist without clobbering each other', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $randomComponent = Livewire::actingAs($user)->test('pages::spelling-bee.play');
    $randomSessionId = $randomComponent->get('gameSessionId');

    $date = now()->startOfDay()->toDateString();
    $dailyComponent = Livewire::actingAs($user)->test('pages::spelling-bee.play', ['dailyDate' => $date]);
    $dailySessionId = $dailyComponent->get('gameSessionId');

    expect(GameSession::count())->toBe(2)
        ->and($randomSessionId)->not->toBe($dailySessionId);

    $resumedRandom = Livewire::actingAs($user)->test('pages::spelling-bee.play');
    expect($resumedRandom->get('gameSessionId'))->toBe($randomSessionId);

    $resumedDaily = Livewire::actingAs($user)->test('pages::spelling-bee.play', ['dailyDate' => $date]);
    expect($resumedDaily->get('gameSessionId'))->toBe($dailySessionId);
});

test('a past daily date is always freely replayable, with no lock and no early session row', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $pastDate = now()->subDays(3)->startOfDay()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::spelling-bee.play', ['dailyDate' => $pastDate])
        ->call('endGame');

    expect(GameSession::sole()->is_daily)->toBeFalse();

    $replay = Livewire::actingAs($user)->test('pages::spelling-bee.play', ['dailyDate' => $pastDate]);
    expect($replay->get('ended'))->toBeFalse();
});

test('a guest gets no lock and no early session row for a daily, even for today', function () {
    $date = now()->startOfDay()->toDateString();

    Livewire::test('pages::spelling-bee.play', ['dailyDate' => $date]);

    expect(GameSession::count())->toBe(0);
});

test("today's daily New Game button links to the archive instead of offering a reroll", function () {
    $date = now()->startOfDay()->toDateString();

    $component = Livewire::test('pages::spelling-bee.play', ['dailyDate' => $date])->call('endGame');

    $component->assertSee(route('daily-puzzles'), false)
        ->assertSee('More Daily Puzzles');
});

test('a finished daily shows the come-back-tomorrow countdown instead of a share link', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $date = now()->startOfDay()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::spelling-bee.play', ['dailyDate' => $date])
        ->call('endGame');

    $resumed = Livewire::actingAs($user)->test('pages::spelling-bee.play', ['dailyDate' => $date]);

    $resumed->assertSee('Next daily puzzle in')
        ->assertDontSee(route('spelling-bee.play', ['seed' => $resumed->get('resolvedSeed')]));
});
