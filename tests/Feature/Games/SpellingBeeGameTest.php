<?php

use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use App\Services\SpellingBee\PuzzleGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * "coupled" (c,o,u,p,l,e,d -- 7 distinct letters) with center "p" -- real
 * dictionary words usable: "couple" (6, not a pangram), "coupled" (7, a
 * pangram, uses every letter), "upped" (5). Overriding letters/center/
 * outerLetters/maxScore after mount() (rather than swapping
 * PuzzleGenerator itself) mirrors QuartilesGameTest's own ->set() pattern.
 */
function spellingBeeComponent()
{
    return Livewire::test('pages::spelling-bee.play')
        ->set('letters', ['c', 'o', 'u', 'p', 'l', 'e', 'd'])
        ->set('center', 'p')
        ->set('outerLetters', ['c', 'o', 'u', 'l', 'e', 'd'])
        // couple=2, coupled=4+7=11, upped=2 -- just needs to be a real,
        // stable number for rank-tier assertions, not exhaustive.
        ->set('maxScore', 15);
}

test('the page renders for a guest, no auth required', function () {
    $this->get('/word-hive')->assertSeeLivewire('pages::spelling-bee.play');
});

test('mount generates 7 distinct letters including the center', function () {
    $component = Livewire::test('pages::spelling-bee.play');

    expect($component->get('letters'))->toHaveCount(7)
        ->and($component->get('letters'))->toContain($component->get('center'));
});

test('the same seed always produces the same puzzle', function () {
    $first = Livewire::test('pages::spelling-bee.play', ['seed' => 42]);
    $second = Livewire::test('pages::spelling-bee.play', ['seed' => 42]);

    expect($first->get('letters'))->toBe($second->get('letters'))
        ->and($first->get('center'))->toBe($second->get('center'));
});

test('resetGame ignores the URL seed and generates a genuinely fresh puzzle', function () {
    $component = Livewire::test('pages::spelling-bee.play', ['seed' => 42]);
    $original = $component->get('letters');

    $component->call('resetGame');

    expect($component->get('letters'))->not->toBe($original);
});

test('pressLetter appends a puzzle letter to the current word', function () {
    $component = spellingBeeComponent()
        ->call('pressLetter', 'c')
        ->call('pressLetter', 'p');

    expect($component->get('currentWord'))->toBe('cp');
});

test('pressLetter ignores a letter not in the puzzle', function () {
    $component = spellingBeeComponent()->call('pressLetter', 'z');

    expect($component->get('currentWord'))->toBe('');
});

test('backspace removes the last character', function () {
    $component = spellingBeeComponent()
        ->set('currentWord', 'cup')
        ->call('backspace');

    expect($component->get('currentWord'))->toBe('cu');
});

test('submitWord scores a valid word, clears the input, and updates the score', function () {
    $component = spellingBeeComponent()
        ->set('currentWord', 'couple')
        ->call('submitWord');

    expect($component->get('foundWords'))->toBe(['couple' => 3])
        ->and($component->get('score'))->toBe(3)
        ->and($component->get('currentWord'))->toBe('');
});

test('submitWord awards the pangram bonus for a word using all 7 letters', function () {
    $component = spellingBeeComponent()
        ->set('currentWord', 'coupled')
        ->call('submitWord');

    // length 7 => 4, +7 pangram bonus => 11.
    expect($component->get('foundWords'))->toBe(['coupled' => 11])
        ->and($component->get('score'))->toBe(11);
});

test('submitWord rejects a word shorter than 4 letters', function () {
    $component = spellingBeeComponent()
        ->set('currentWord', 'cup')
        ->call('submitWord');

    expect($component->get('foundWords'))->toBe([])
        ->and($component->get('message'))->toBe('Too short.');
});

test('submitWord rejects a word missing the center letter', function () {
    // "cued" -- c,u,e,d all in the letter set, but no "p".
    $component = spellingBeeComponent()
        ->set('currentWord', 'cued')
        ->call('submitWord');

    expect($component->get('foundWords'))->toBe([])
        ->and($component->get('message'))->toBe('Missing the center letter.');
});

test('submitWord rejects a word using a letter outside the puzzle', function () {
    // "puppy" -- has the center "p" but uses "y", not in the letter set.
    $component = spellingBeeComponent()
        ->set('currentWord', 'puppy')
        ->call('submitWord');

    expect($component->get('foundWords'))->toBe([])
        ->and($component->get('message'))->toBe('Uses a letter not in the puzzle.');
});

test('submitWord rejects a word not in the dictionary', function () {
    // "poule" -- p + only letters in the set, but not a real word.
    $component = spellingBeeComponent()
        ->set('currentWord', 'poulc')
        ->call('submitWord');

    expect($component->get('foundWords'))->toBe([])
        ->and($component->get('message'))->toBe('Not a word.');
});

test('submitWord rejects a word already found', function () {
    $component = spellingBeeComponent()
        ->set('currentWord', 'couple')->call('submitWord');

    expect($component->get('score'))->toBe(3);

    $component->set('currentWord', 'couple')->call('submitWord');

    expect($component->get('score'))->toBe(3)
        ->and($component->get('message'))->toBe('Already found.');
});

test('shuffleLetters only reorders the outer letters, never the center or canonical letter set', function () {
    $component = spellingBeeComponent();
    $letters = $component->get('letters');
    $center = $component->get('center');

    $component->call('shuffleLetters');

    expect($component->get('letters'))->toBe($letters)
        ->and($component->get('center'))->toBe($center)
        ->and($component->get('outerLetters'))->toEqualCanonicalizing(array_values(array_diff($letters, [$center])));
});

test('endGame persists a GameSession and GameParticipant for an authenticated user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user);
    $component = spellingBeeComponent()
        ->set('currentWord', 'couple')->call('submitWord')
        ->call('endGame');

    expect($component->get('ended'))->toBeTrue();

    $session = GameSession::sole();
    expect($session->type)->toBe(GameType::SpellingBee);

    $participant = GameParticipant::sole();
    expect($participant->user_id)->toBe($user->id)
        ->and($participant->score)->toBe(3)
        ->and($participant->stats['words_found'])->toBe(1)
        ->and($participant->stats['total_valid_words'])->toBeInt();
});

test('endGame for a guest creates a guest GameParticipant', function () {
    $component = spellingBeeComponent()->call('endGame');

    expect($component->get('ended'))->toBeTrue();

    $participant = GameParticipant::sole();
    expect($participant->user_id)->toBeNull()
        ->and($participant->guest_name)->toBe('Guest');
});

test('a signed-in user gets an in-progress GameSession the moment a puzzle is generated, before ending it', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $component = spellingBeeComponent();

    $session = GameSession::sole();
    expect($session->user_id)->toBe($user->id)
        ->and($session->ended_at)->toBeNull()
        ->and($component->get('gameSessionId'))->toBe($session->id);
});

test('a guest gets no early GameSession -- only endGame() ever creates one', function () {
    spellingBeeComponent();

    expect(GameSession::count())->toBe(0);
});

test('leaving and revisiting resumes the saved in-progress game exactly as left', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    spellingBeeComponent()
        ->set('currentWord', 'couple')->call('submitWord');

    $resumed = Livewire::actingAs($user)->test('pages::spelling-bee.play');

    expect($resumed->get('foundWords'))->toBe(['couple' => 3])
        ->and($resumed->get('score'))->toBe(3)
        ->and($resumed->get('currentWord'))->toBe('') // never persisted
        ->and(GameSession::count())->toBe(1);
});

test('New Game reuses the same unfinished session row instead of creating a duplicate', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $component = spellingBeeComponent();
    $firstSessionId = $component->get('gameSessionId');

    $component->call('resetGame');

    expect(GameSession::count())->toBe(1)
        ->and($component->get('gameSessionId'))->toBe($firstSessionId);
});

test('actions are no-ops once the game has ended', function () {
    $component = spellingBeeComponent()->call('endGame');

    $component->set('currentWord', 'couple')->call('submitWord');
    expect($component->get('foundWords'))->toBe([]);

    $component->call('pressLetter', 'c');
    expect($component->get('currentWord'))->toBe('');
});

test('resetGame generates a fresh puzzle and clears all found state', function () {
    $component = spellingBeeComponent()
        ->set('currentWord', 'couple')->call('submitWord')
        ->call('endGame');

    $component->call('resetGame');

    expect($component->get('ended'))->toBeFalse()
        ->and($component->get('foundWords'))->toBe([])
        ->and($component->get('score'))->toBe(0)
        ->and($component->get('allWords'))->toBeNull()
        ->and($component->get('gameSessionId'))->toBeNull()
        ->and($component->get('letters'))->toHaveCount(7);
});

test('the reveal screen shows the possible-words list, grouped by length, once the game ends', function () {
    $component = spellingBeeComponent()
        ->set('currentWord', 'couple')->call('submitWord')
        ->call('endGame');

    $component->assertSee('All Possible Words')
        ->assertSee('COUPLE');
});

test('found words are listed during play, not just at the end-game reveal', function () {
    $component = spellingBeeComponent()
        ->set('currentWord', 'couple')->call('submitWord');

    expect($component->get('ended'))->toBeFalse();

    $component->assertSee('Words found')
        ->assertSee('COUPLE');
});

test('rank rises as score approaches maxScore, and reaching it shows Hive Master', function () {
    $component = spellingBeeComponent()->set('maxScore', 4);

    expect($component->get('rank'))->toBe('Beginner');

    $component->set('score', 4);
    expect($component->get('rank'))->toBe('Hive Master');
});

test('foundWordsSorted lists found words alphabetically, not find order', function () {
    $component = spellingBeeComponent()
        ->set('currentWord', 'upped')->call('submitWord')
        ->set('currentWord', 'couple')->call('submitWord');

    expect(array_keys($component->get('foundWordsSorted')))->toBe(['couple', 'upped']);
});

test('the reveal screen shows a copyable share link built from the resolved seed', function () {
    $component = Livewire::test('pages::spelling-bee.play', ['seed' => 42])->call('endGame');

    $component->assertSee(route('spelling-bee.play', ['seed' => 42]));
});

/**
 * docs/concerns.md #9: "Four Letters (5/11)" per-length progress columns.
 */
test('totalWordsByLength is computed at generation time and matches the real per-length answer counts', function () {
    $component = Livewire::test('pages::spelling-bee.play', ['seed' => 10]);

    $expected = [];
    foreach (app(PuzzleGenerator::class)->wordsFor($component->get('letters'), $component->get('center')) as $word) {
        $expected[mb_strlen($word)] = ($expected[mb_strlen($word)] ?? 0) + 1;
    }
    ksort($expected);

    expect($component->get('totalWordsByLength'))->toBe($expected);
});

test('foundWordsByLength groups found words by length with their points, alphabetical within each group', function () {
    $component = spellingBeeComponent()
        ->set('totalWordsByLength', [5 => 7, 6 => 1, 7 => 1])
        ->set('currentWord', 'coupled')->call('submitWord')
        ->set('currentWord', 'upped')->call('submitWord');

    $grouped = $component->get('foundWordsByLength');

    expect($grouped)->toHaveKeys([5, 7])
        ->and($grouped)->not->toHaveKey(6)
        ->and(array_keys($grouped[5]))->toBe(['upped'])
        ->and(array_keys($grouped[7]))->toBe(['coupled'])
        ->and($grouped[7]['coupled'])->toBe($component->get('foundWords')['coupled']);
});

test('a session saved before totalWordsByLength existed re-derives it on resume instead of showing an empty progress panel', function () {
    $user = User::factory()->create();

    $session = GameSession::factory()->create([
        'user_id' => $user->id,
        'type' => GameType::SpellingBee,
        'is_daily' => false,
        'settings' => ['seed' => 10],
        'state' => [
            'letters' => ['t', 'i', 'c', 'k', 'l', 'e', 'r'],
            'center' => 'k',
            'outerLetters' => ['t', 'i', 'c', 'l', 'e', 'r'],
            'foundWords' => [],
            'score' => 0,
            'maxScore' => 100,
            // No 'totalWordsByLength' key -- simulates a session persisted
            // before this feature existed.
        ],
        'ended_at' => null,
    ]);

    $component = Livewire::actingAs($user)->test('pages::spelling-bee.play');

    expect($component->get('gameSessionId'))->toBe($session->id);
    expect($component->get('totalWordsByLength'))->not->toBe([]);
});
