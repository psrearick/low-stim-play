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
    expect($component->get('trayRemaining'))->toHaveCount(28);
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

    // Cells 0 and 2 are two apart on the same row -- not adjacent.
    $component->call('placeDomino', '0-1', 0, 2);

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
