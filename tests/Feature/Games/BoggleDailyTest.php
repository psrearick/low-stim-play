<?php

use App\Models\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a daily link produces a deterministic board regardless of who opens it', function () {
    $date = now()->startOfDay()->toDateString();

    $first = Livewire::test('pages::boggle.play', ['dailyDate' => $date]);
    $second = Livewire::test('pages::boggle.play', ['dailyDate' => $date]);

    expect($first->get('board'))->toBe($second->get('board'));
});

test('a daily puzzle shows an in-play badge naming it as such, unlike a random game', function () {
    $date = now()->startOfDay()->toDateString();

    Livewire::test('pages::boggle.play', ['dailyDate' => $date])
        ->assertSee('Daily Puzzle')
        ->assertSee(now()->startOfDay()->format('M j, Y'));

    Livewire::test('pages::boggle.play', ['size' => 4, 'minutes' => 0])
        ->assertDontSee('Daily Puzzle');
});

test('a daily always resolves to an untimed, size-4 board regardless of query overrides', function () {
    $date = now()->startOfDay()->toDateString();

    $component = Livewire::test('pages::boggle.play', ['dailyDate' => $date, 'size' => 5, 'minutes' => 3, 'seed' => 999]);

    expect($component->get('size'))->toBe(4)
        ->and($component->get('minutes'))->toBe(0)
        ->and($component->get('endsAt'))->toBeNull();
});

test("a signed-in user gets an early GameSession for today's daily, tagged is_daily", function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $date = now()->startOfDay()->toDateString();
    $component = Livewire::test('pages::boggle.play', ['dailyDate' => $date]);

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
        ->test('pages::boggle.play', ['dailyDate' => $date])
        ->set('board', boggleCatsBoard())
        ->set('path', [0, 1, 2])
        ->call('submitWord')
        ->call('endGame');

    $resumed = Livewire::actingAs($user)->test('pages::boggle.play', ['dailyDate' => $date]);

    expect($resumed->get('ended'))->toBeTrue()
        ->and($resumed->get('dailyAlreadyDone'))->toBeTrue()
        ->and($resumed->get('score'))->toBe(1)
        ->and($resumed->get('foundWords'))->toBe(['cat' => 1]);

    expect(GameSession::count())->toBe(1);
});

test('reloading an in-progress daily resumes found words and score', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $date = now()->startOfDay()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::boggle.play', ['dailyDate' => $date])
        ->set('board', boggleCatsBoard())
        ->set('path', [0, 1, 2])
        ->call('submitWord');

    $resumed = Livewire::actingAs($user)->test('pages::boggle.play', ['dailyDate' => $date]);

    expect($resumed->get('ended'))->toBeFalse()
        ->and($resumed->get('dailyAlreadyDone'))->toBeFalse()
        ->and($resumed->get('score'))->toBe(1)
        ->and($resumed->get('foundWords'))->toBe(['cat' => 1]);
});

/**
 * Regression coverage: Boggle never had any resumable-in-progress
 * persistence before dailies existed (only the shared/challenge session
 * restore path did), so this confirms the newly-added early-row/persist
 * machinery is scoped strictly to is_daily and doesn't interfere with an
 * ordinary solo game, which still only gets a GameSession at finishGame().
 */
test('an ordinary solo game is unaffected by daily session bookkeeping', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    Livewire::actingAs($user)
        ->test('pages::boggle.play', ['size' => 4, 'minutes' => 0])
        ->set('board', boggleCatsBoard())
        ->set('path', [0, 1, 2])
        ->call('submitWord');

    expect(GameSession::count())->toBe(0);
});

test('a past daily date is always freely replayable, with no lock and no early session row', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $pastDate = now()->subDays(3)->startOfDay()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::boggle.play', ['dailyDate' => $pastDate])
        ->call('endGame');

    expect(GameSession::sole()->is_daily)->toBeFalse();

    $replay = Livewire::actingAs($user)->test('pages::boggle.play', ['dailyDate' => $pastDate]);
    expect($replay->get('ended'))->toBeFalse();
});

test('a guest gets no lock and no early session row for a daily, even for today', function () {
    $date = now()->startOfDay()->toDateString();

    Livewire::test('pages::boggle.play', ['dailyDate' => $date]);

    expect(GameSession::count())->toBe(0);
});

test("today's daily New Game button links to the archive instead of offering a reroll", function () {
    $date = now()->startOfDay()->toDateString();

    $component = Livewire::test('pages::boggle.play', ['dailyDate' => $date])->call('endGame');

    $component->assertSee(route('daily-puzzles'), false)
        ->assertSee('More Daily Puzzles');
});

test('a finished daily shows the come-back-tomorrow countdown instead of a challenge link', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $date = now()->startOfDay()->toDateString();

    $component = Livewire::actingAs($user)
        ->test('pages::boggle.play', ['dailyDate' => $date])
        ->call('endGame');

    $component->assertSee('Next daily puzzle in')
        ->assertDontSee(route('boggle.share', ['gameSession' => $component->get('gameSessionId')]));
});
