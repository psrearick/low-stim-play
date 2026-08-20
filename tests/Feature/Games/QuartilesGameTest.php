<?php

use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * 5 groups of 4 single-letter tiles each, spelling real dictionary words
 * (cats/dogs/bird/fish/moon) -- checked directly against the real word
 * list (confirmed present in storage/app/words.txt), the same "use real
 * short words, not a stubbed dictionary" approach
 * tests/Feature/Games/BoggleGameTest.php's own boggleCatsBoard() fixture
 * takes. Overriding $tiles/$groups/$unfoundOrder after mount() (rather
 * than swapping PuzzleGenerator itself) mirrors that same test's
 * ->set('board', ...) pattern.
 *
 * @return array{tiles: array<int, array{display: string, match: string, groupIndex: int}>, groups: list<list<int>>, unfoundOrder: list<int>}
 */
function quartilesFixture(): array
{
    $words = ['cats', 'dogs', 'bird', 'fish', 'moon'];
    $tiles = [];
    $groups = [];
    $id = 0;

    foreach ($words as $groupIndex => $word) {
        $groupIds = [];

        foreach (str_split($word) as $char) {
            $tiles[$id] = ['display' => strtoupper($char), 'match' => $char, 'groupIndex' => $groupIndex];
            $groupIds[] = $id;
            $id++;
        }

        $groups[] = $groupIds;
    }

    return ['tiles' => $tiles, 'groups' => $groups, 'unfoundOrder' => array_keys($tiles)];
}

function quartilesComponent()
{
    $fixture = quartilesFixture();

    return Livewire::test('pages::quartiles.play')
        ->set('tiles', $fixture['tiles'])
        ->set('groups', $fixture['groups'])
        ->set('unfoundOrder', $fixture['unfoundOrder']);
}

test('the page renders for a guest, no auth required', function () {
    $this->get('/word-blocks')->assertSeeLivewire('pages::quartiles.play');
});

test('the trademarked "Quartiles" name never appears on the page, only "Quads"', function () {
    // docs/specs/quartiles.md's own header: this game ships under the
    // display name "Word Blocks" (and the 4-tile groups are called
    // "Quads" in-page) specifically because "Quartiles" is NYT Games'
    // trademarked name for this mechanic, same as Boggle/Yahtzee/Family
    // Feud/Apples-to-Apples before their own renames.
    $this->get('/word-blocks')
        ->assertDontSee('Quartiles')
        ->assertSee('Quads');
});

test('mount generates 20 tiles across 5 groups of 4', function () {
    $component = Livewire::test('pages::quartiles.play');

    expect($component->get('tiles'))->toHaveCount(20)
        ->and($component->get('groups'))->toHaveCount(5);

    foreach ($component->get('groups') as $group) {
        expect($group)->toHaveCount(4);
    }
});

test('the same seed always produces the same puzzle', function () {
    $first = Livewire::test('pages::quartiles.play', ['seed' => 42]);
    $second = Livewire::test('pages::quartiles.play', ['seed' => 42]);

    expect($first->get('tiles'))->toBe($second->get('tiles'))
        ->and($first->get('groups'))->toBe($second->get('groups'));
});

test('resetGame ignores the URL seed and generates a genuinely fresh puzzle', function () {
    $component = Livewire::test('pages::quartiles.play', ['seed' => 42]);
    $original = $component->get('tiles');

    $component->call('resetGame');

    expect($component->get('tiles'))->not->toBe($original);
});

test('toggleTile selects and deselects tiles, capped at 4', function () {
    $component = quartilesComponent()
        ->call('toggleTile', 0)
        ->call('toggleTile', 1)
        ->call('toggleTile', 2)
        ->call('toggleTile', 3);

    expect($component->get('selected'))->toBe([0, 1, 2, 3]);

    // A 5th tile is a no-op while 4 are already selected.
    $component->call('toggleTile', 4);
    expect($component->get('selected'))->toBe([0, 1, 2, 3]);

    // Toggling an already-selected tile removes it.
    $component->call('toggleTile', 1);
    expect($component->get('selected'))->toBe([0, 2, 3]);
});

test('clearSelection empties the current selection', function () {
    $component = quartilesComponent()
        ->call('toggleTile', 0)
        ->call('clearSelection');

    expect($component->get('selected'))->toBe([]);
});

test('submitWord scores a valid word, clears the selection, and updates the score', function () {
    $component = quartilesComponent()
        ->call('toggleTile', 0)
        ->call('toggleTile', 1)
        ->call('toggleTile', 2)
        ->call('toggleTile', 3)
        ->call('submitWord');

    expect($component->get('foundWords'))->toBe(['cats' => 8])
        ->and($component->get('score'))->toBe(8)
        ->and($component->get('selected'))->toBe([]);
});

test('submitWord rejects a word not in the dictionary', function () {
    // Tiles 0(c) + 4(d) -> "cd", not a word.
    $component = quartilesComponent()
        ->call('toggleTile', 0)
        ->call('toggleTile', 4)
        ->call('submitWord');

    expect($component->get('foundWords'))->toBe([])
        ->and($component->get('score'))->toBe(0)
        ->and($component->get('message'))->toBe('Not a word.');
});

test('submitWord rejects a word already found', function () {
    $component = quartilesComponent()
        ->call('toggleTile', 0)->call('toggleTile', 1)->call('toggleTile', 2)->call('toggleTile', 3)
        ->call('submitWord');

    expect($component->get('score'))->toBe(8);

    $component->call('toggleTile', 0)->call('toggleTile', 1)->call('toggleTile', 2)->call('toggleTile', 3)
        ->call('submitWord');

    expect($component->get('score'))->toBe(8)
        ->and($component->get('message'))->toBe('Already found.');
});

test('submitting a quartile group\'s exact 4 tiles, in any order, marks it found', function () {
    // Group 0 is [0,1,2,3] (c,a,t,s), whose designated word is "cats" --
    // submitted instead as [3,0,1,2] ("scat", a different real word using
    // the exact same 4 tiles) to confirm detection is by tile-id *set*,
    // not selection order or a match against the original generation word.
    $component = quartilesComponent()
        ->call('toggleTile', 3)->call('toggleTile', 0)->call('toggleTile', 1)->call('toggleTile', 2)
        ->call('submitWord');

    expect($component->get('foundWords'))->toBe(['scat' => 8])
        ->and($component->get('foundGroups'))->toBe([0])
        ->and($component->get('unfoundOrder'))->not->toContain(0, 1, 2, 3);
});

test('a different 4-tile combination spelling an answer word does not count as that Quartile', function () {
    // "dogs" (group 1's own word) spelled from tiles {4,5,6,3} instead of
    // group 1's real set {4,5,6,7} -- tile 3 is "s" from the "cats" group.
    $component = quartilesComponent()
        ->call('toggleTile', 4)->call('toggleTile', 5)->call('toggleTile', 6)->call('toggleTile', 3)
        ->call('submitWord');

    expect($component->get('foundWords'))->toBe(['dogs' => 8])
        ->and($component->get('foundGroups'))->toBe([]);
});

test('found-quartile tiles remain selectable and combinable with other tiles', function () {
    $component = quartilesComponent()
        ->call('toggleTile', 0)->call('toggleTile', 1)->call('toggleTile', 2)->call('toggleTile', 3)
        ->call('submitWord');

    expect($component->get('foundGroups'))->toBe([0]);

    // Tile 0 ("c") is now in a found quartile -- still selectable, e.g.
    // combined with tile 16 ("m") to spell "cm", not a real word, but the
    // point is the toggle itself succeeds.
    $component->call('toggleTile', 0)->call('toggleTile', 16);

    expect($component->get('selected'))->toBe([0, 16]);
});

test('shuffleTiles only reorders tiles not yet in a found quartile', function () {
    $component = quartilesComponent()
        ->call('toggleTile', 0)->call('toggleTile', 1)->call('toggleTile', 2)->call('toggleTile', 3)
        ->call('submitWord');

    expect($component->get('groups')[0])->toBe([0, 1, 2, 3]);

    $component->call('shuffleTiles');

    expect($component->get('groups')[0])->toBe([0, 1, 2, 3])
        ->and($component->get('unfoundOrder'))->toEqualCanonicalizing(range(4, 19))
        ->and($component->get('foundGroups'))->toBe([0]);
});

test('finding all 5 quartiles surfaces a banner without ending the game', function () {
    $component = quartilesComponent();

    foreach ($component->get('groups') as $group) {
        $component->set('selected', $group)->call('submitWord');
    }

    expect($component->get('foundGroups'))->toHaveCount(5)
        ->and($component->get('ended'))->toBeFalse()
        ->and($component->get('allValidWords'))->not->toBeNull()
        ->and($component->get('banner'))->not->toBeNull();
});

test('endGame persists a GameSession and GameParticipant for an authenticated user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user);
    $component = quartilesComponent()
        ->call('toggleTile', 0)->call('toggleTile', 1)->call('toggleTile', 2)->call('toggleTile', 3)
        ->call('submitWord')
        ->call('endGame');

    expect($component->get('ended'))->toBeTrue();

    $session = GameSession::sole();
    expect($session->type)->toBe(GameType::Quartiles);

    $participant = GameParticipant::sole();
    expect($participant->user_id)->toBe($user->id)
        ->and($participant->score)->toBe(8)
        ->and($participant->stats['words_found'])->toBe(1)
        ->and($participant->stats['quartiles_found'])->toBe(1)
        ->and($participant->stats['total_valid_words'])->toBeInt();
});

test('endGame for a guest creates a guest GameParticipant', function () {
    $component = quartilesComponent()->call('endGame');

    expect($component->get('ended'))->toBeTrue();

    $participant = GameParticipant::sole();
    expect($participant->user_id)->toBeNull()
        ->and($participant->guest_name)->toBe('Guest');
});

test('a signed-in user gets an in-progress GameSession the moment a puzzle is generated, before ending it', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $component = quartilesComponent();

    $session = GameSession::sole();
    expect($session->user_id)->toBe($user->id)
        ->and($session->ended_at)->toBeNull()
        ->and($component->get('gameSessionId'))->toBe($session->id);
});

test('a guest gets no early GameSession -- only endGame() ever creates one', function () {
    quartilesComponent();

    expect(GameSession::count())->toBe(0);
});

test('progress is saved after every scoring submission and after shuffling', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $component = quartilesComponent()
        ->call('toggleTile', 0)->call('toggleTile', 1)->call('toggleTile', 2)->call('toggleTile', 3)
        ->call('submitWord');

    $session = GameSession::sole();
    expect($session->state['foundWords'])->toBe(['cats' => 8])
        ->and($session->state['score'])->toBe(8);

    $component->call('shuffleTiles');

    expect(GameSession::sole()->state['unfoundOrder'])->toEqualCanonicalizing($component->get('unfoundOrder'));
});

test('leaving and revisiting resumes the saved in-progress game exactly as left', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    quartilesComponent()
        ->call('toggleTile', 0)->call('toggleTile', 1)->call('toggleTile', 2)->call('toggleTile', 3)
        ->call('submitWord');

    // A brand new component instance -- simulates leaving and coming back,
    // with nothing overridden this time, so mount() must do the resuming
    // on its own from the saved GameSession rather than generating fresh.
    $resumed = Livewire::actingAs($user)->test('pages::quartiles.play');

    expect($resumed->get('foundWords'))->toBe(['cats' => 8])
        ->and($resumed->get('score'))->toBe(8)
        // The fixture's own tiles (id 0 = "C"), not a freshly generated
        // random puzzle -- proves the *saved* puzzle was actually loaded,
        // not just regenerated (both happen to have 20 tiles either way).
        ->and($resumed->get('tiles')[0]['display'])->toBe('C')
        ->and($resumed->get('selected'))->toBe([]) // never persisted
        ->and(GameSession::count())->toBe(1); // resuming must not create a second row
});

test('New Game reuses the same unfinished session row instead of creating a duplicate', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $component = quartilesComponent();
    $firstSessionId = $component->get('gameSessionId');

    $component->call('resetGame');

    expect(GameSession::count())->toBe(1)
        ->and($component->get('gameSessionId'))->toBe($firstSessionId);
});

test('New Game after ending creates a genuinely new session, leaving the finished one untouched', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $component = quartilesComponent()->call('endGame');
    $finishedSessionId = $component->get('gameSessionId');

    $component->call('resetGame');

    expect(GameSession::count())->toBe(2)
        ->and($component->get('gameSessionId'))->not->toBe($finishedSessionId)
        ->and(GameSession::find($finishedSessionId)->ended_at)->not->toBeNull();
});

test('an explicit seed always starts a fresh puzzle instead of resuming a saved one', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $first = quartilesComponent();
    $firstTiles = $first->get('tiles');

    $overridden = Livewire::actingAs($user)->test('pages::quartiles.play', ['seed' => 12345]);

    expect($overridden->get('tiles'))->not->toBe($firstTiles)
        ->and(GameSession::count())->toBe(1); // reuses the same row, doesn't duplicate
});

test('actions are no-ops once the game has ended', function () {
    $component = quartilesComponent()->call('endGame');

    $component->call('toggleTile', 0);
    expect($component->get('selected'))->toBe([]);

    $component->set('unfoundOrder', range(4, 19))->call('shuffleTiles');
    // shuffleTiles is a no-op post-end -- the explicit set above proves it
    // (an actual shuffle call would still produce *some* permutation, but
    // a no-op leaves the just-set value untouched).
    expect($component->get('unfoundOrder'))->toBe(range(4, 19));
});

test('resetGame generates a fresh puzzle and clears all found state', function () {
    $component = quartilesComponent()
        ->call('toggleTile', 0)->call('toggleTile', 1)->call('toggleTile', 2)->call('toggleTile', 3)
        ->call('submitWord')
        ->call('endGame');

    $component->call('resetGame');

    expect($component->get('ended'))->toBeFalse()
        ->and($component->get('foundWords'))->toBe([])
        ->and($component->get('foundGroups'))->toBe([])
        ->and($component->get('score'))->toBe(0)
        ->and($component->get('allValidWords'))->toBeNull()
        ->and($component->get('gameSessionId'))->toBeNull()
        ->and($component->get('tiles'))->toHaveCount(20);
});

test('the reveal screen renders the possible-words list and quartile answers once the game ends', function () {
    $component = quartilesComponent()
        ->call('toggleTile', 0)->call('toggleTile', 1)->call('toggleTile', 2)->call('toggleTile', 3)
        ->call('submitWord')
        ->call('endGame');

    $component->assertSee('All Possible Words')
        ->assertSee('CATS');
});

test('found words are listed during play, not just at the end-game reveal', function () {
    $component = quartilesComponent()
        ->call('toggleTile', 0)->call('toggleTile', 1)->call('toggleTile', 2)->call('toggleTile', 3)
        ->call('submitWord');

    expect($component->get('ended'))->toBeFalse();

    $component->assertSee('Words found')
        ->assertSee('CATS');
});

test('foundWordsSorted lists found words alphabetically, not in find order', function () {
    $component = quartilesComponent()
        // Group 1 (dogs, tiles 4-7) found first...
        ->call('toggleTile', 4)->call('toggleTile', 5)->call('toggleTile', 6)->call('toggleTile', 7)
        ->call('submitWord')
        // ...then group 0 (cats, tiles 0-3) second.
        ->call('toggleTile', 0)->call('toggleTile', 1)->call('toggleTile', 2)->call('toggleTile', 3)
        ->call('submitWord');

    expect(array_keys($component->get('foundWordsSorted')))->toBe(['cats', 'dogs']);
});

test('selectedWord shows the concatenated uppercase text of the current selection', function () {
    $component = quartilesComponent()
        ->call('toggleTile', 0)->call('toggleTile', 1)->call('toggleTile', 2)->call('toggleTile', 3);

    expect($component->get('selectedWord'))->toBe('CATS');
});
