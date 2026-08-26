<?php

use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Overwrites the just-mounted session's stored secret answer, the only way
 * to make guess-evaluation deterministic in a test -- AnswerPicker always
 * draws from the real curated word pool, so the actual answer is otherwise
 * unpredictable. Mirrors resolveAnswer()'s own read path exactly (`settings
 * .answer`), so this only works once a GameSession row already exists
 * (true for every mount path except a guest playing a daily -- see
 * WordleDailyTest.php for that one).
 */
function setWordleAnswer($component, string $answer): void
{
    $session = GameSession::find($component->get('gameSessionId'));
    $session->update(['settings' => array_merge($session->settings, ['answer' => $answer])]);
}

test('the page renders for a guest, no auth required', function () {
    $this->get('/five-letters')->assertSeeLivewire('pages::wordle.play');
});

test('the trademarked "Wordle" name never appears on the page, only "Five Letters"', function () {
    $this->get('/five-letters')
        ->assertDontSee('Wordle')
        ->assertSee('Five Letters');
});

test('a fresh visit shows the pre-game setup screen instead of starting immediately', function () {
    // TODO Task 28: Hard Mode moved from an in-game toggle to a setup
    // screen choice -- nothing about the playthrough (word, GameSession
    // row) should exist until startGame() actually runs.
    $component = Livewire::test('pages::wordle.play');

    $component->assertSee('Start Game')
        ->assertDontSee('wordle-input', false);

    expect($component->get('started'))->toBeFalse()
        ->and(GameSession::count())->toBe(0);
});

test('actions are no-ops before the game has started', function () {
    $component = Livewire::test('pages::wordle.play');

    $component->call('pressKey', 'a')->call('submitGuess');

    expect($component->get('currentGuess'))->toBe('')
        ->and($component->get('guesses'))->toBe([])
        ->and(GameSession::count())->toBe(0);
});

test('starting an ordinary game creates an early GameSession row for a guest too, unlike Word Blocks', function () {
    // Deliberate divergence from Quartiles' own "a guest gets no early row"
    // behavior -- see docs/specs/wordle.md section 3: the secret answer has
    // to live somewhere server-side from the very first request, since
    // (unlike Word Blocks) there's no public seed to regenerate it from on
    // demand.
    Livewire::test('pages::wordle.play')->call('startGame');

    $session = GameSession::sole();
    expect($session->type)->toBe(GameType::Wordle)
        ->and($session->user_id)->toBeNull()
        ->and($session->settings)->toHaveKey('answer');
});

test('starting an ordinary game creates an early GameSession row for a signed-in user', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $component = Livewire::test('pages::wordle.play')->call('startGame');

    $session = GameSession::sole();
    expect($session->user_id)->toBe($user->id)
        ->and($session->ended_at)->toBeNull()
        ->and($component->get('gameSessionId'))->toBe($session->id);
});

test('the secret answer is never exposed on any public property while playing', function () {
    $component = Livewire::test('pages::wordle.play')->call('startGame');
    setWordleAnswer($component, 'crane');

    $component->set('currentGuess', 'chase')->call('submitGuess');

    // answerReveal stays null while the game is still in progress -- it's
    // only ever populated by finishGame() (see docs/specs/wordle.md
    // section 1). Every other public property that could plausibly leak
    // the answer text is checked explicitly too, not just this one.
    expect($component->get('answerReveal'))->toBeNull()
        ->and($component->get('currentGuess'))->not->toBe('crane')
        ->and(json_encode($component->get('guesses')))->not->toContain('"crane"')
        ->and($component->get('message'))->toBeNull();
});

test('submitGuess rejects a guess of the wrong length', function () {
    $component = Livewire::test('pages::wordle.play')->call('startGame')
        ->set('currentGuess', 'ab')
        ->call('submitGuess');

    expect($component->get('guesses'))->toBe([])
        ->and($component->get('message'))->toContain('5 letters');
});

test('submitGuess rejects a guess not in the dictionary', function () {
    $component = Livewire::test('pages::wordle.play')->call('startGame')
        ->set('currentGuess', 'zzzzz')
        ->call('submitGuess');

    expect($component->get('guesses'))->toBe([])
        ->and($component->get('message'))->toBe('Not a word.');
});

test('submitGuess evaluates correct, present, and absent letters correctly', function () {
    $component = Livewire::test('pages::wordle.play')->call('startGame');
    setWordleAnswer($component, 'crane');

    // "raise" vs "crane": r present, a present, i absent, s absent, e correct.
    $component->set('currentGuess', 'raise')->call('submitGuess');

    expect($component->get('guesses'))->toBe([
        ['word' => 'raise', 'result' => ['present', 'present', 'absent', 'absent', 'correct']],
    ])->and($component->get('currentGuess'))->toBe('');
});

test('a winning guess ends the game, scores it, and reveals the answer', function () {
    $component = Livewire::test('pages::wordle.play')->call('startGame');
    setWordleAnswer($component, 'crane');

    $component->set('currentGuess', 'crane')->call('submitGuess');

    expect($component->get('ended'))->toBeTrue()
        ->and($component->get('won'))->toBeTrue()
        ->and($component->get('answerReveal'))->toBe('crane');

    // TODO Task 21: score is the guess count itself (lower is better), not
    // a derived points total.
    $participant = GameParticipant::sole();
    expect($participant->score)->toBe(1)
        ->and($participant->stats['won'])->toBeTrue()
        ->and($participant->stats['guess_count'])->toBe(1);
});

test('using all 6 guesses without winning ends the game with a worse-than-any-win sentinel score, and reveals the answer', function () {
    $component = Livewire::test('pages::wordle.play')->call('startGame');
    setWordleAnswer($component, 'crane');

    foreach (['stone', 'plumb', 'light', 'words', 'happy', 'zonal'] as $guess) {
        $component->set('currentGuess', $guess)->call('submitGuess');
    }

    expect($component->get('ended'))->toBeTrue()
        ->and($component->get('won'))->toBeFalse()
        ->and($component->get('answerReveal'))->toBe('crane')
        ->and($component->get('guesses'))->toHaveCount(6);

    // TODO Task 21: a loss scores one worse than any possible win
    // (MAX_GUESSES + 1 = 7), not 0 -- still an integer GameParticipant.score
    // needs, but on the "lower is better" scale a loss should sort worse
    // than every real win, not better than a 6-guess one.
    $participant = GameParticipant::sole();
    expect($participant->score)->toBe(7)
        ->and($participant->stats['won'])->toBeFalse()
        ->and($participant->stats['guess_count'])->toBeNull();
});

test('a guest ended game creates a guest GameParticipant', function () {
    $component = Livewire::test('pages::wordle.play')->call('startGame');
    setWordleAnswer($component, 'crane');

    $component->set('currentGuess', 'crane')->call('submitGuess');

    $participant = GameParticipant::sole();
    expect($participant->user_id)->toBeNull()
        ->and($participant->guest_name)->toBe('Guest');
});

test('toggleHardMode only works on the pre-game setup screen', function () {
    // TODO Task 28: Hard Mode is a setup-time choice now, not an in-game
    // toggle -- toggling before startGame() should stick; toggling after
    // should be a no-op regardless of how many guesses have been made.
    $component = Livewire::test('pages::wordle.play');

    $component->call('toggleHardMode');
    expect($component->get('hardMode'))->toBeTrue();

    $component->call('startGame');

    $component->call('toggleHardMode');
    expect($component->get('hardMode'))->toBeTrue(); // unchanged, locked once started
});

test('hard mode rejects a guess that abandons an already-revealed correct letter', function () {
    $component = Livewire::test('pages::wordle.play')->call('toggleHardMode')->call('startGame');
    setWordleAnswer($component, 'crane');

    // "chase": c correct at position 0.
    $component->set('currentGuess', 'chase')->call('submitGuess');
    expect($component->get('guesses'))->toHaveCount(1);

    // "words" doesn't start with c -- must be rejected without consuming a guess.
    $component->set('currentGuess', 'words')->call('submitGuess');

    expect($component->get('guesses'))->toHaveCount(1)
        ->and($component->get('message'))->toContain('position 1');
});

test('hard mode accepts a guess that correctly reuses every revealed letter', function () {
    $component = Livewire::test('pages::wordle.play')->call('toggleHardMode')->call('startGame');
    setWordleAnswer($component, 'crane');

    $component->set('currentGuess', 'chase')->call('submitGuess');
    // "crane" itself always reuses everything it already revealed about itself.
    $component->set('currentGuess', 'crane')->call('submitGuess');

    expect($component->get('ended'))->toBeTrue()
        ->and($component->get('won'))->toBeTrue();
});

test('keyboardStatus reflects the best-known status per letter across every guess', function () {
    $component = Livewire::test('pages::wordle.play')->call('startGame');
    setWordleAnswer($component, 'crane');

    // "arena" vs "crane": a present (pos0) then absent (pos4, only one 'a'
    // in the answer) -- best status wins, so 'a' should read present, not
    // absent. r is an exact match at pos1.
    $component->set('currentGuess', 'arena')->call('submitGuess');

    $status = $component->get('keyboardStatus');
    expect($status['a'])->toBe('present')
        ->and($status['r'])->toBe('correct');
});

test('tapping a tile in the current row selects it, and the next typed letter overwrites just that column', function () {
    $component = Livewire::test('pages::wordle.play')->call('startGame')
        ->set('currentGuess', 'apple');

    $component->call('selectCell', 1)->call('pressKey', 'x');

    expect($component->get('currentGuess'))->toBe('axple');
});

test('overwriting a tile auto-advances the selection to the next column', function () {
    $component = Livewire::test('pages::wordle.play')->call('startGame')
        ->set('currentGuess', 'apple');

    $component->call('selectCell', 1)
        ->call('pressKey', 'x') // overwrites col 1, advances to col 2
        ->call('pressKey', 'y'); // overwrites col 2

    expect($component->get('currentGuess'))->toBe('axyle');
});

test('selecting the column exactly at the current guess length behaves like a plain append', function () {
    $component = Livewire::test('pages::wordle.play')->call('startGame')
        ->set('currentGuess', 'app');

    $component->call('selectCell', 3)->call('pressKey', 'l');

    expect($component->get('currentGuess'))->toBe('appl')
        ->and($component->get('selectedCol'))->toBe(4);
});

test('selectCell rejects a column beyond the current guess length or the word length', function () {
    $component = Livewire::test('pages::wordle.play')->call('startGame')
        ->set('currentGuess', 'ap');

    $component->call('selectCell', 4); // nothing typed there yet -- not selectable
    expect($component->get('selectedCol'))->toBeNull();

    $component->call('selectCell', 10); // past the word length entirely
    expect($component->get('selectedCol'))->toBeNull();
});

test('backspace clears an active tile selection and falls back to plain append', function () {
    $component = Livewire::test('pages::wordle.play')->call('startGame')
        ->set('currentGuess', 'apple')
        ->call('selectCell', 1);

    expect($component->get('selectedCol'))->toBe(1);

    $component->call('backspace');

    expect($component->get('selectedCol'))->toBeNull()
        ->and($component->get('currentGuess'))->toBe('appl');

    $component->call('pressKey', 'e');
    expect($component->get('currentGuess'))->toBe('apple'); // appended at the end, not overwriting col 1
});

test('submitting a guess clears any active tile selection', function () {
    $component = Livewire::test('pages::wordle.play')->call('startGame');
    setWordleAnswer($component, 'crane');

    $component->set('currentGuess', 'chase')->call('selectCell', 2)->call('submitGuess');

    expect($component->get('selectedCol'))->toBeNull();
});

test('tile selection and pressKey/backspace are no-ops before the game has started', function () {
    $component = Livewire::test('pages::wordle.play');

    $component->call('selectCell', 0);
    expect($component->get('selectedCol'))->toBeNull();
});

test('New Game returns to the setup screen, then reuses the same unfinished session row instead of creating a duplicate', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $component = Livewire::test('pages::wordle.play')->call('startGame');
    $firstSessionId = $component->get('gameSessionId');

    $component->call('resetGame');
    expect($component->get('started'))->toBeFalse();

    $component->call('startGame');

    expect(GameSession::count())->toBe(1)
        ->and($component->get('gameSessionId'))->toBe($firstSessionId);
});

test('New Game after ending creates a genuinely new session, leaving the finished one untouched', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $component = Livewire::test('pages::wordle.play')->call('startGame');
    setWordleAnswer($component, 'crane');
    $component->set('currentGuess', 'crane')->call('submitGuess');
    $finishedSessionId = $component->get('gameSessionId');

    $component->call('resetGame')->call('startGame');

    expect(GameSession::count())->toBe(2)
        ->and($component->get('gameSessionId'))->not->toBe($finishedSessionId)
        ->and(GameSession::find($finishedSessionId)->ended_at)->not->toBeNull();
});

test('actions are no-ops once the game has ended', function () {
    $component = Livewire::test('pages::wordle.play')->call('startGame');
    setWordleAnswer($component, 'crane');
    $component->set('currentGuess', 'crane')->call('submitGuess');

    $component->set('currentGuess', 'plumb')->call('submitGuess');

    expect($component->get('guesses'))->toHaveCount(1); // still just the winning guess
});

test('the reveal screen shows guesses-to-win on a win, or the answer on a loss', function () {
    $winComponent = Livewire::test('pages::wordle.play')->call('startGame');
    setWordleAnswer($winComponent, 'crane');
    $winComponent->set('currentGuess', 'crane')->call('submitGuess');
    $winComponent->assertSee('Solved in 1 guess');

    $lossComponent = Livewire::test('pages::wordle.play')->call('startGame');
    setWordleAnswer($lossComponent, 'crane');
    foreach (['stone', 'plumb', 'light', 'words', 'happy', 'zonal'] as $guess) {
        $lossComponent->set('currentGuess', $guess)->call('submitGuess');
    }
    $lossComponent->assertSee('CRANE');
});
