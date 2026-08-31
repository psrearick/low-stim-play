<?php

use App\Enums\PipsDifficulty;
use App\Models\GameSession;
use App\Models\User;
use App\Services\Pips\PuzzleGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a daily link produces a deterministic puzzle regardless of who opens it', function () {
    $date = now()->startOfDay()->toDateString();

    $first = Livewire::test('pages::pips.play', ['dailyDate' => $date, 'difficulty' => 'easy']);
    $second = Livewire::test('pages::pips.play', ['dailyDate' => $date, 'difficulty' => 'easy']);

    expect($first->get('board'))->toBe($second->get('board'));
});

test('a daily puzzle shows an in-play badge naming it as such, unlike a random game', function () {
    $date = now()->startOfDay()->toDateString();

    Livewire::test('pages::pips.play', ['dailyDate' => $date, 'difficulty' => 'easy'])
        ->assertSee('Daily Puzzle')
        ->assertSee(now()->startOfDay()->format('M j, Y'));

    Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy'])
        ->assertDontSee('Daily Puzzle');
});

test("a signed-in user gets an early GameSession for today's daily, tagged is_daily", function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $date = now()->startOfDay()->toDateString();
    $component = Livewire::test('pages::pips.play', ['dailyDate' => $date, 'difficulty' => 'easy']);

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
        ->test('pages::pips.play', ['dailyDate' => $date, 'difficulty' => 'easy'])
        ->call('giveUp');

    $resumed = Livewire::actingAs($user)->test('pages::pips.play', ['dailyDate' => $date, 'difficulty' => 'easy']);

    expect($resumed->get('ended'))->toBeTrue()
        ->and($resumed->get('dailyAlreadyDone'))->toBeTrue();

    expect(GameSession::count())->toBe(1);
});

test('reloading an in-progress daily resumes placements', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $date = now()->startOfDay()->toDateString();

    $component = Livewire::actingAs($user)->test('pages::pips.play', ['dailyDate' => $date, 'difficulty' => 'easy']);
    $puzzle = app(PuzzleGenerator::class)->forSeed($component->get('resolvedSeed'), PipsDifficulty::Easy);
    $placement = $puzzle->solution['placements'][0];
    $lo = min($placement['valueA'], $placement['valueB']);
    $hi = max($placement['valueA'], $placement['valueB']);

    $component->call('placeDomino', "{$lo}-{$hi}", $placement['cellA'], $placement['cellB']);

    $resumed = Livewire::actingAs($user)->test('pages::pips.play', ['dailyDate' => $date, 'difficulty' => 'easy']);

    expect($resumed->get('ended'))->toBeFalse()
        ->and($resumed->get('dailyAlreadyDone'))->toBeFalse()
        ->and($resumed->get('placements'))->toHaveKey($placement['cellA']);
});

/**
 * Each of the three difficulty tiers needs its own independent daily lock
 * (docs/specs/pips.md section 3) -- playing Easy today must not lock out
 * Medium/Hard for the same date.
 */
test('finishing one difficulty today does not lock out the other two tiers', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $date = now()->startOfDay()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::pips.play', ['dailyDate' => $date, 'difficulty' => 'easy'])
        ->call('giveUp');

    $medium = Livewire::actingAs($user)->test('pages::pips.play', ['dailyDate' => $date, 'difficulty' => 'medium']);
    $hard = Livewire::actingAs($user)->test('pages::pips.play', ['dailyDate' => $date, 'difficulty' => 'hard']);

    expect($medium->get('ended'))->toBeFalse()
        ->and($medium->get('dailyAlreadyDone'))->toBeFalse()
        ->and($hard->get('ended'))->toBeFalse()
        ->and($hard->get('dailyAlreadyDone'))->toBeFalse();

    expect(GameSession::count())->toBe(3);
});

test('an in-progress random game and an in-progress daily coexist without clobbering each other', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $randomComponent = Livewire::actingAs($user)->test('pages::pips.play', ['difficulty' => 'easy']);
    $randomSessionId = $randomComponent->get('gameSessionId');

    $date = now()->startOfDay()->toDateString();
    $dailyComponent = Livewire::actingAs($user)->test('pages::pips.play', ['dailyDate' => $date, 'difficulty' => 'easy']);
    $dailySessionId = $dailyComponent->get('gameSessionId');

    expect(GameSession::count())->toBe(2)
        ->and($randomSessionId)->not->toBe($dailySessionId);
});

test('a past daily date is always freely replayable, with no lock and no early session row', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $pastDate = now()->subDays(3)->startOfDay()->toDateString();

    Livewire::actingAs($user)->test('pages::pips.play', ['dailyDate' => $pastDate, 'difficulty' => 'easy']);

    expect(GameSession::count())->toBe(0);
});
