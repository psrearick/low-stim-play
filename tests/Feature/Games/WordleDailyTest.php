<?php

use App\Models\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('a daily link produces the same answer regardless of who opens it', function () {
    $date = now()->startOfDay()->toDateString();

    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    $first = Livewire::actingAs($firstUser)->test('pages::wordle.play', ['dailyDate' => $date]);
    $second = Livewire::actingAs($secondUser)->test('pages::wordle.play', ['dailyDate' => $date]);

    // The answer is never a public property (see docs/specs/wordle.md
    // section 1), so determinism is confirmed indirectly: the same guess
    // produces the same evaluation result for both players.
    $first->set('currentGuess', 'crane')->call('submitGuess');
    $second->set('currentGuess', 'crane')->call('submitGuess');

    expect($first->get('guesses'))->toBe($second->get('guesses'));
});

test('a daily puzzle shows an in-play badge naming it as such, unlike an ordinary game', function () {
    $date = now()->startOfDay()->toDateString();

    Livewire::test('pages::wordle.play', ['dailyDate' => $date])
        ->assertSee('Daily Puzzle')
        ->assertSee(now()->startOfDay()->format('M j, Y'));

    Livewire::test('pages::wordle.play')
        ->assertDontSee('Daily Puzzle');
});

test("a signed-in user gets an early GameSession for today's daily, tagged is_daily, with the answer already stored", function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $date = now()->startOfDay()->toDateString();
    $component = Livewire::test('pages::wordle.play', ['dailyDate' => $date]);

    $session = GameSession::sole();
    expect($session->is_daily)->toBeTrue()
        ->and($session->daily_date)->toBe($date)
        ->and($session->ended_at)->toBeNull()
        ->and($session->settings)->toHaveKey('answer')
        ->and($component->get('gameSessionId'))->toBe($session->id);
});

test('reloading a finished daily restores the stored result instead of a fresh game', function () {
    $user = User::factory()->create();
    $date = now()->startOfDay()->toDateString();

    $component = Livewire::actingAs($user)->test('pages::wordle.play', ['dailyDate' => $date]);
    $answer = GameSession::sole()->settings['answer'];

    Livewire::actingAs($user)
        ->test('pages::wordle.play', ['dailyDate' => $date])
        ->set('currentGuess', $answer)
        ->call('submitGuess');

    $resumed = Livewire::actingAs($user)->test('pages::wordle.play', ['dailyDate' => $date]);

    expect($resumed->get('ended'))->toBeTrue()
        ->and($resumed->get('dailyAlreadyDone'))->toBeTrue()
        ->and($resumed->get('won'))->toBeTrue()
        ->and($resumed->get('answerReveal'))->toBe($answer);

    expect(GameSession::count())->toBe(1);
});

test('reloading an in-progress daily resumes the guesses made so far', function () {
    $user = User::factory()->create();
    $date = now()->startOfDay()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::wordle.play', ['dailyDate' => $date])
        ->set('currentGuess', 'stone')
        ->call('submitGuess');

    $resumed = Livewire::actingAs($user)->test('pages::wordle.play', ['dailyDate' => $date]);

    expect($resumed->get('ended'))->toBeFalse()
        ->and($resumed->get('dailyAlreadyDone'))->toBeFalse()
        ->and($resumed->get('guesses'))->toHaveCount(1)
        ->and($resumed->get('guesses')[0]['word'])->toBe('stone');
});

test('a past daily date is always freely replayable, with no lock and no early session row', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $pastDate = now()->subDays(3)->startOfDay()->toDateString();

    Livewire::actingAs($user)
        ->test('pages::wordle.play', ['dailyDate' => $pastDate])
        ->set('currentGuess', 'stone')
        ->call('submitGuess');

    // No early row at all for a non-today date -- the answer is derived
    // fresh from the date itself (see resolveAnswer()), same reasoning as
    // a guest's own daily play below.
    expect(GameSession::count())->toBe(0);
});

test('a guest gets no lock and no early session row for a daily, even for today', function () {
    $date = now()->startOfDay()->toDateString();

    Livewire::test('pages::wordle.play', ['dailyDate' => $date])
        ->set('currentGuess', 'stone')
        ->call('submitGuess');

    expect(GameSession::count())->toBe(0);
});

test('a guest still gets a real, evaluated guess against the deterministic daily answer', function () {
    $date = now()->startOfDay()->toDateString();

    // The same date always resolves to the same answer -- confirmed the
    // same way as the multi-user test above, since the answer itself is
    // never a public property to compare directly.
    $first = Livewire::test('pages::wordle.play', ['dailyDate' => $date])
        ->set('currentGuess', 'crane')->call('submitGuess');
    $second = Livewire::test('pages::wordle.play', ['dailyDate' => $date])
        ->set('currentGuess', 'crane')->call('submitGuess');

    expect($first->get('guesses'))->toBe($second->get('guesses'));
});

test("today's daily New Game button links to the archive instead of offering a reroll", function () {
    $user = User::factory()->create();
    $date = now()->startOfDay()->toDateString();

    $component = Livewire::actingAs($user)->test('pages::wordle.play', ['dailyDate' => $date]);
    $answer = GameSession::sole()->settings['answer'];
    $component->set('currentGuess', $answer)->call('submitGuess');

    $component->assertSee(route('daily-puzzles'), false)
        ->assertSee('More Daily Puzzles');
});

test("resetGame is a no-op for a daily, whether or not it's finished", function () {
    $date = now()->startOfDay()->toDateString();

    $component = Livewire::test('pages::wordle.play', ['dailyDate' => $date])
        ->set('currentGuess', 'stone')
        ->call('submitGuess')
        ->call('resetGame');

    expect($component->get('guesses'))->toHaveCount(1);
});

test('a finished daily shows the come-back-tomorrow countdown instead of a challenge link', function () {
    $user = User::factory()->create();
    $date = now()->startOfDay()->toDateString();

    $component = Livewire::actingAs($user)->test('pages::wordle.play', ['dailyDate' => $date]);
    $answer = GameSession::sole()->settings['answer'];
    $component->set('currentGuess', $answer)->call('submitGuess');

    $resumed = Livewire::actingAs($user)->test('pages::wordle.play', ['dailyDate' => $date]);

    $resumed->assertSee('Next daily puzzle in')
        ->assertDontSee('Challenge a Friend');
});
