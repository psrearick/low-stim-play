<?php

use App\Enums\GameType;
use App\Enums\PipsDifficulty;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\PipsPuzzle;
use App\Models\User;
use App\Services\Pips\PuzzleGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Places every domino from a generated puzzle's own solution, in order --
 * the deterministic way to drive a component to a full, valid solve via the
 * tap-to-select server API (docs/specs/pips.md section 3 -- Feature tests
 * exercise selectTrayDomino()/placeDomino() directly, the same "fallback API
 * the test suite drives directly" shape Boggle's own selectCell()/
 * clearPath() already established).
 */
function solvePips(Testable $component, PipsPuzzle $puzzle): void
{
    foreach ($puzzle->solution['placements'] as $placement) {
        $lo = min($placement['valueA'], $placement['valueB']);
        $hi = max($placement['valueA'], $placement['valueB']);
        $dominoId = "{$lo}-{$hi}";
        $flip = $placement['valueA'] === $hi;

        $component->call('placeDomino', $dominoId, $placement['cellA'], $placement['cellB'], $flip);
    }
}

test('the page renders for a guest, no auth required', function () {
    $this->get('/pips')->assertSeeLivewire('pages::pips.play');
});

test('mount defaults to Medium difficulty when none is given', function () {
    $component = Livewire::test('pages::pips.play');
    $board = $component->get('board');

    // dimensions is the grown shape's own *tight* bounding box (App\Enums\
    // PipsDifficulty's own rows()/cols() docblock), never larger than the
    // tier's oversized bounding box those growth attempts had room to
    // wander within -- cells (the real, active board size) is the exact
    // invariant to check instead.
    expect($component->get('resolvedDifficulty'))->toBe('medium');
    expect($board['dimensions']['rows'])->toBeLessThanOrEqual(PipsDifficulty::Medium->rows());
    expect($board['dimensions']['cols'])->toBeLessThanOrEqual(PipsDifficulty::Medium->cols());
    expect($board['cells'])->toHaveCount(PipsDifficulty::Medium->cellCount());
});

test('an explicit difficulty resolves to that tier\'s board size', function () {
    $component = Livewire::test('pages::pips.play', ['difficulty' => 'hard']);
    $board = $component->get('board');

    expect($component->get('resolvedDifficulty'))->toBe('hard');
    expect($board['dimensions']['rows'])->toBeLessThanOrEqual(PipsDifficulty::Hard->rows());
    expect($board['dimensions']['cols'])->toBeLessThanOrEqual(PipsDifficulty::Hard->cols());
    expect($board['cells'])->toHaveCount(PipsDifficulty::Hard->cellCount());
    expect($component->get('trayRemaining'))->toHaveCount(PipsDifficulty::Hard->dominoCount());
});

test('the same seed and difficulty always produce the same puzzle', function () {
    $first = Livewire::test('pages::pips.play', ['seed' => 42, 'difficulty' => 'easy']);
    $second = Livewire::test('pages::pips.play', ['seed' => 42, 'difficulty' => 'easy']);

    expect($first->get('board'))->toBe($second->get('board'));
});

test('the same seed at a different difficulty is a different puzzle', function () {
    $easy = Livewire::test('pages::pips.play', ['seed' => 42, 'difficulty' => 'easy']);
    $hard = Livewire::test('pages::pips.play', ['seed' => 42, 'difficulty' => 'hard']);

    expect($easy->get('board'))->not->toBe($hard->get('board'));
});

test('placeDomino places a valid domino via the tap-to-select path', function () {
    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy']);
    $puzzle = app(PuzzleGenerator::class)->forSeed(1, PipsDifficulty::Easy);
    $placement = $puzzle->solution['placements'][0];
    $lo = min($placement['valueA'], $placement['valueB']);
    $hi = max($placement['valueA'], $placement['valueB']);
    $dominoId = "{$lo}-{$hi}";

    $component->call('selectTrayDomino', $dominoId)
        ->call('placeDomino', $dominoId, $placement['cellA'], $placement['cellB'], $placement['valueA'] === $hi);

    expect($component->get('placements'))->toHaveKey($placement['cellA'])
        ->and($component->get('placements')[$placement['cellA']]['value'])->toBe($placement['valueA'])
        ->and($component->get('trayRemaining'))->not->toContain($dominoId)
        ->and($component->get('selectedDominoId'))->toBeNull();
});

test('placeDomino rejects non-adjacent cells', function () {
    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy']);
    $dominoId = collect($component->get('trayRemaining'))->first();

    // Cells 0 and 2 are two apart on the same row -- not adjacent.
    $component->call('placeDomino', $dominoId, 0, 2);

    expect($component->get('placements'))->toBe([])
        ->and($component->get('message'))->not->toBeNull();
});

test('placeDomino rejects a cell that is already covered', function () {
    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy']);
    $puzzle = app(PuzzleGenerator::class)->forSeed(1, PipsDifficulty::Easy);
    $placement = $puzzle->solution['placements'][0];
    $lo = min($placement['valueA'], $placement['valueB']);
    $hi = max($placement['valueA'], $placement['valueB']);
    $dominoId = "{$lo}-{$hi}";

    $component->call('placeDomino', $dominoId, $placement['cellA'], $placement['cellB']);
    $countAfterFirst = count($component->get('placements'));

    // A second, different domino trying to reuse $placement['cellA'] (still
    // adjacent to $placement['cellB']) must be rejected regardless of which
    // domino it is.
    $otherDominoId = collect($component->get('trayRemaining'))->first();
    $component->call('placeDomino', $otherDominoId, $placement['cellA'], $placement['cellB']);

    expect($component->get('placements'))->toHaveCount($countAfterFirst);
});

test('placeDomino rejects a domino id not in the tray', function () {
    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy']);

    $component->call('placeDomino', '9-9', 0, 1);

    expect($component->get('placements'))->toBe([]);
});

test('a region never shows satisfied until it is fully filled, even if the constraint could resolve early', function () {
    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy']);

    $component->set('board', [
        'dimensions' => ['rows' => 1, 'cols' => 2],
        'cells' => [0, 1],
        'regions' => [
            ['id' => 0, 'cells' => [0, 1], 'constraint' => ['type' => 'less', 'value' => 100], 'color' => 0, 'badge' => null],
        ],
        'dominoes' => ['0-1'],
    ]);
    $component->set('trayRemaining', ['0-1']);

    // A "less than 100" constraint on a 2-cell, maxPip=4 region is
    // trivially true (max possible sum is 8) -- ConstraintChecker itself
    // correctly resolves that as Satisfied even with zero cells filled,
    // but that's not a meaningful "you got it right" signal to show a
    // player who hasn't placed anything, so it reads as Unknown until the
    // region is actually filled (docs/DEVLOG.md's Pips entry).
    expect($component->get('regionStatus')[0])->toBe('Unknown');

    $component->call('placeDomino', '0-1', 0, 1, false);

    expect($component->get('regionStatus')[0])->toBe('Satisfied');
});

/**
 * Same 2x2 layout as pipsTwoByTwoBoard(), but with an 'equal' constraint
 * over all four cells -- one that a fully-filled board can actually fail,
 * unlike a 'none' constraint (always Satisfied regardless of values),
 * needed to exercise a genuinely *incorrect* full board (docs/concerns.md).
 */
function pipsTwoByTwoEqualBoard(): array
{
    $board = pipsTwoByTwoBoard();
    $board['regions'][0]['constraint'] = ['type' => 'equal', 'value' => null];

    return $board;
}

test('boardIncorrect stays false while the board is only partially filled', function () {
    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy']);
    $component->set('board', pipsTwoByTwoEqualBoard());
    $component->set('trayRemaining', ['0-1', '2-3']);

    $component->call('placeDomino', '0-1', 0, 1, flip: false);

    expect($component->get('boardIncorrect'))->toBeFalse();
});

test('boardIncorrect turns true once every cell is filled but a region is broken, without ending the game', function () {
    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy']);
    $component->set('board', pipsTwoByTwoEqualBoard());
    $component->set('trayRemaining', ['0-1', '2-3']);

    // Values 0,1,2,3 are all different -- an 'equal' constraint over all
    // four cells is broken.
    $component->call('placeDomino', '0-1', 0, 1, flip: false)
        ->call('placeDomino', '2-3', 2, 3, flip: false);

    expect($component->get('boardIncorrect'))->toBeTrue()
        ->and($component->get('ended'))->toBeFalse();
});

test('boardIncorrect clears the moment the player interacts with an incorrect board, and stays hidden until the board is full again', function () {
    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy']);
    $component->set('board', pipsTwoByTwoEqualBoard());
    $component->set('trayRemaining', ['0-1', '2-3']);

    $component->call('placeDomino', '0-1', 0, 1, flip: false)
        ->call('placeDomino', '2-3', 2, 3, flip: false);
    expect($component->get('boardIncorrect'))->toBeTrue();

    $component->call('removePlacement', '2-3');
    expect($component->get('boardIncorrect'))->toBeFalse();

    // Filled again, still wrong -- the banner reappears (nothing about a
    // one-time-only dismissal), it's purely derived from current state.
    $component->call('placeDomino', '2-3', 2, 3, flip: false);
    expect($component->get('boardIncorrect'))->toBeTrue();
});

test('boardIncorrect is false once the board is completely and correctly filled', function () {
    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy']);
    $component->set('board', pipsTwoByTwoEqualBoard());
    $component->set('trayRemaining', []);

    // boardIncorrect is purely derived from current state (docs/concerns.md
    // -- never a stored flag), so setting a complete, correct fill directly
    // is enough to exercise it, with no action call needed to "trigger" a
    // recheck.
    $component->set('placements', [
        0 => ['value' => 1, 'dominoId' => '0-1'],
        1 => ['value' => 1, 'dominoId' => '0-1'],
        2 => ['value' => 1, 'dominoId' => '2-3'],
        3 => ['value' => 1, 'dominoId' => '2-3'],
    ]);

    expect($component->get('boardIncorrect'))->toBeFalse();
});

test('solving the whole board ends the game as solved with a positive score', function () {
    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy']);
    $puzzle = app(PuzzleGenerator::class)->forSeed(1, PipsDifficulty::Easy);

    solvePips($component, $puzzle);

    expect($component->get('ended'))->toBeTrue()
        ->and($component->get('solved'))->toBeTrue()
        ->and($component->get('trayRemaining'))->toBe([]);

    $participant = GameParticipant::sole();
    expect($participant->score)->toBeGreaterThan(0)
        ->and($participant->stats['solved'])->toBeTrue()
        ->and($participant->stats['difficulty'])->toBe('easy');
});

test('giving up ends the game unsolved with a score of zero', function () {
    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy'])
        ->call('giveUp');

    expect($component->get('ended'))->toBeTrue()
        ->and($component->get('solved'))->toBeFalse();

    $participant = GameParticipant::sole();
    expect($participant->score)->toBe(0)
        ->and($participant->stats['solved'])->toBeFalse();
});

test('removePlacement returns a placed domino to the tray', function () {
    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy']);
    $puzzle = app(PuzzleGenerator::class)->forSeed(1, PipsDifficulty::Easy);
    $placement = $puzzle->solution['placements'][0];
    $lo = min($placement['valueA'], $placement['valueB']);
    $hi = max($placement['valueA'], $placement['valueB']);
    $dominoId = "{$lo}-{$hi}";

    $component->call('placeDomino', $dominoId, $placement['cellA'], $placement['cellB']);
    expect($component->get('trayRemaining'))->not->toContain($dominoId);

    $component->call('removePlacement', $dominoId);

    expect($component->get('placements'))->not->toHaveKey($placement['cellA'])
        ->and($component->get('trayRemaining'))->toContain($dominoId);
});

/**
 * A tight, fully-controlled 2x2 board -- (0,0)=0 (1,0)=1 / (0,1)=2 (1,1)=3
 * in row-major order -- rather than a real generated puzzle's own
 * irregular shape, so the exact geometry a pivot-rotation depends on
 * (docs/concerns.md) is deterministic instead of incidental to whatever a
 * given seed happens to grow.
 */
function pipsTwoByTwoBoard(): array
{
    return [
        'dimensions' => ['rows' => 2, 'cols' => 2],
        'cells' => [0, 1, 2, 3],
        'regions' => [
            ['id' => 0, 'cells' => [0, 1, 2, 3], 'constraint' => ['type' => 'none', 'value' => null], 'color' => 0, 'badge' => null],
        ],
        'dominoes' => ['0-1', '2-3'],
    ];
}

test('rotatePlacement pivots a placed domino 90 degrees clockwise around the given cell', function () {
    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy']);
    $component->set('board', pipsTwoByTwoBoard());
    $component->set('trayRemaining', ['0-1', '2-3']);

    // A horizontal domino at cells 0 (left, pivot) and 1 (right) -- rotating
    // it clockwise around cell 0 should swing cell 1's value down to cell 2
    // (east becomes south), leaving cell 0 untouched.
    $component->call('placeDomino', '0-1', 0, 1, flip: false);
    $component->call('rotatePlacement', '0-1', 0);

    expect($component->get('placements'))->not->toHaveKey(1)
        ->and($component->get('placements')[0]['value'])->toBe(0)
        ->and($component->get('placements')[2]['value'])->toBe(1)
        ->and($component->get('placements')[2]['dominoId'])->toBe('0-1');
});

test('rotatePlacement silently does nothing when the landing cell is off the board', function () {
    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy']);
    $component->set('board', pipsTwoByTwoBoard());
    $component->set('trayRemaining', ['0-1', '2-3']);

    // Pivoting around cell 1 (the right cell of a top-row domino) swings
    // cell 0's value to row -1 -- off the board entirely.
    $component->call('placeDomino', '0-1', 0, 1, flip: false);
    $component->call('rotatePlacement', '0-1', 1);

    expect($component->get('placements')[0]['value'])->toBe(0)
        ->and($component->get('placements')[1]['value'])->toBe(1)
        ->and($component->get('message'))->toBeNull();
});

test('rotatePlacement silently does nothing when the landing cell is already covered', function () {
    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy']);
    $component->set('board', pipsTwoByTwoBoard());
    $component->set('trayRemaining', ['0-1', '2-3']);

    $component->call('placeDomino', '0-1', 0, 1, flip: false)
        ->call('placeDomino', '2-3', 2, 3, flip: false);

    // Pivoting around cell 0 would swing cell 1's value onto cell 2 -- but
    // cell 2 is already covered by the other domino.
    $component->call('rotatePlacement', '0-1', 0);

    expect($component->get('placements')[0]['value'])->toBe(0)
        ->and($component->get('placements')[1]['value'])->toBe(1)
        ->and($component->get('placements')[2]['dominoId'])->toBe('2-3');
});

test('rotatePlacement does nothing once the game has ended', function () {
    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy']);
    $component->set('board', pipsTwoByTwoBoard());
    $component->set('trayRemaining', ['0-1', '2-3']);

    $component->call('placeDomino', '0-1', 0, 1, flip: false)
        ->call('giveUp')
        ->call('rotatePlacement', '0-1', 0);

    expect($component->get('placements')[0]['value'])->toBe(0)
        ->and($component->get('placements')[1]['value'])->toBe(1);
});

test('movePlacement relocates an already-placed domino to a new cell pair', function () {
    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy']);
    $puzzle = app(PuzzleGenerator::class)->forSeed(1, PipsDifficulty::Easy);
    $first = $puzzle->solution['placements'][0];
    $second = $puzzle->solution['placements'][1];
    $lo = min($first['valueA'], $first['valueB']);
    $hi = max($first['valueA'], $first['valueB']);
    $dominoId = "{$lo}-{$hi}";

    $component->call('placeDomino', $dominoId, $first['cellA'], $first['cellB'], flip: false);

    $component->call('movePlacement', $dominoId, $second['cellA'], $second['cellB'], false);

    expect($component->get('placements'))->not->toHaveKey($first['cellA'])
        ->and($component->get('placements'))->not->toHaveKey($first['cellB'])
        ->and($component->get('placements')[$second['cellA']]['value'])->toBe($lo)
        ->and($component->get('placements')[$second['cellB']]['value'])->toBe($hi)
        ->and($component->get('trayRemaining'))->not->toContain($dominoId);
});

test('movePlacement can drop a domino back onto its own current cells', function () {
    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy']);
    $puzzle = app(PuzzleGenerator::class)->forSeed(1, PipsDifficulty::Easy);
    $placement = $puzzle->solution['placements'][0];
    $lo = min($placement['valueA'], $placement['valueB']);
    $hi = max($placement['valueA'], $placement['valueB']);
    $dominoId = "{$lo}-{$hi}";

    $component->call('placeDomino', $dominoId, $placement['cellA'], $placement['cellB'], flip: false);

    // Same two cells, flipped orientation -- must not be rejected as
    // "already covered" merely because the domino already occupies them.
    $component->call('movePlacement', $dominoId, $placement['cellA'], $placement['cellB'], true);

    expect($component->get('placements')[$placement['cellA']]['value'])->toBe($hi)
        ->and($component->get('placements')[$placement['cellB']]['value'])->toBe($lo);
});

test('movePlacement rejects a target cell already covered by a different domino, without losing the original placement', function () {
    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy']);
    $puzzle = app(PuzzleGenerator::class)->forSeed(1, PipsDifficulty::Easy);
    $first = $puzzle->solution['placements'][0];
    $second = $puzzle->solution['placements'][1];
    $firstLo = min($first['valueA'], $first['valueB']);
    $firstHi = max($first['valueA'], $first['valueB']);
    $firstDominoId = "{$firstLo}-{$firstHi}";
    $secondLo = min($second['valueA'], $second['valueB']);
    $secondHi = max($second['valueA'], $second['valueB']);
    $secondDominoId = "{$secondLo}-{$secondHi}";

    $component->call('placeDomino', $firstDominoId, $first['cellA'], $first['cellB'], flip: false)
        ->call('placeDomino', $secondDominoId, $second['cellA'], $second['cellB'], flip: false);

    // Try to relocate the first domino onto the second domino's own cells.
    $component->call('movePlacement', $firstDominoId, $second['cellA'], $second['cellB'], false);

    expect($component->get('placements')[$first['cellA']]['value'])->toBe($firstLo)
        ->and($component->get('placements')[$first['cellB']]['value'])->toBe($firstHi)
        ->and($component->get('placements')[$second['cellA']]['value'])->toBe($secondLo)
        ->and($component->get('message'))->not->toBeNull();
});

test('movePlacement rejects non-adjacent target cells, without losing the original placement', function () {
    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy']);
    $puzzle = app(PuzzleGenerator::class)->forSeed(1, PipsDifficulty::Easy);
    $placement = $puzzle->solution['placements'][0];
    $lo = min($placement['valueA'], $placement['valueB']);
    $hi = max($placement['valueA'], $placement['valueB']);
    $dominoId = "{$lo}-{$hi}";

    $component->call('placeDomino', $dominoId, $placement['cellA'], $placement['cellB'], flip: false);

    $component->call('movePlacement', $dominoId, $placement['cellA'], $placement['cellA'] + 10, false);

    expect($component->get('placements')[$placement['cellA']]['value'])->toBe($lo)
        ->and($component->get('message'))->not->toBeNull();
});

test('a signed-in user gets an in-progress GameSession the moment a puzzle is generated', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy']);

    $session = GameSession::sole();
    expect($session->user_id)->toBe($user->id)
        ->and($session->type)->toBe(GameType::Pips)
        ->and($session->ended_at)->toBeNull()
        ->and($component->get('gameSessionId'))->toBe($session->id);
});

test('leaving and revisiting resumes the saved in-progress game exactly as left', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    $component = Livewire::test('pages::pips.play', ['seed' => 1, 'difficulty' => 'easy']);
    $puzzle = app(PuzzleGenerator::class)->forSeed(1, PipsDifficulty::Easy);
    $placement = $puzzle->solution['placements'][0];
    $lo = min($placement['valueA'], $placement['valueB']);
    $hi = max($placement['valueA'], $placement['valueB']);
    $dominoId = "{$lo}-{$hi}";

    $component->call('placeDomino', $dominoId, $placement['cellA'], $placement['cellB']);

    $resumed = Livewire::actingAs($user)->test('pages::pips.play');

    expect($resumed->get('placements'))->toHaveKey($placement['cellA'])
        ->and($resumed->get('resolvedDifficulty'))->toBe('easy')
        ->and(GameSession::count())->toBe(1);
});

test('an explicit difficulty in the URL abandons a stale in-progress session of a different tier', function () {
    $user = User::factory()->create();
    Livewire::actingAs($user);

    Livewire::test('pages::pips.play', ['difficulty' => 'hard']);
    expect(GameSession::sole()->settings['difficulty'])->toBe('hard');

    Livewire::actingAs($user)->test('pages::pips.play', ['difficulty' => 'easy']);

    expect(GameSession::count())->toBe(1);
    expect(GameSession::sole()->settings['difficulty'])->toBe('easy');
});
