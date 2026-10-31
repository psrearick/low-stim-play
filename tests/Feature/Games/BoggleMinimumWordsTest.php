<?php

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\User;
use App\Services\Boggle\BoardGenerator;
use App\Services\Boggle\Solver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * BoardGenerator::generatePlayable() -- a freshly dealt Word Grid board
 * never has fewer than MIN_WORDS findable words. Uses the real dictionary
 * (storage/app/words.txt), so the counts here are the ones players get.
 */
beforeEach(function () {
    $this->generator = new BoardGenerator;
    $this->solver = new Solver;
});

/**
 * The first seed counting up from 0 whose raw generate() board falls
 * under the minimum -- found rather than hard-coded so the test can't go
 * stale if the dice or dictionary ever change.
 */
function sparseSeed(BoardGenerator $generator, Solver $solver): int
{
    for ($seed = 0; ; $seed++) {
        if (count($solver->solve($generator->generate(4, $seed))) < BoardGenerator::MIN_WORDS) {
            return $seed;
        }
    }
}

test('a freshly dealt board always has at least the minimum number of words', function (int $size) {
    for ($i = 0; $i < 200; $i++) {
        expect(count($this->solver->solve($this->generator->generatePlayable($size, $this->solver))))
            ->toBeGreaterThanOrEqual(BoardGenerator::MIN_WORDS);
    }
})->with([4, 5]);

test('a sparse starting seed is rerolled to a different, richer board', function () {
    $seed = sparseSeed($this->generator, $this->solver);

    $board = $this->generator->generatePlayable(4, $this->solver, $seed);

    expect($board['seed'])->not->toBe($seed)
        ->and(count($this->solver->solve($board)))->toBeGreaterThanOrEqual(BoardGenerator::MIN_WORDS);
});

test('a starting seed that already meets the minimum is kept as-is', function () {
    for ($seed = 0; count($this->solver->solve($this->generator->generate(4, $seed))) < BoardGenerator::MIN_WORDS; $seed++) {
    }

    expect($this->generator->generatePlayable(4, $this->solver, $seed))
        ->toBe($this->generator->generate(4, $seed));
});

test('rerolling is deterministic, and the returned seed replays the exact board', function () {
    $seed = sparseSeed($this->generator, $this->solver);

    $first = $this->generator->generatePlayable(4, $this->solver, $seed);
    $second = $this->generator->generatePlayable(4, $this->solver, $seed);

    expect($first)->toBe($second)
        ->and($this->generator->generate(4, $first['seed']))->toBe($first);
});

test('if no board can reach the minimum, the richest one found is returned instead of failing', function () {
    $wordsPath = tempnam(sys_get_temp_dir(), 'boggle-words-');
    file_put_contents($wordsPath, implode("\n", ['eat', 'tea', 'ate', 'net', 'ten']));

    $board = $this->generator->generatePlayable(4, new Solver($wordsPath), 1);

    expect($board['cells'])->toHaveCount(16);
});

test('a room game is dealt a board with at least the minimum number of words', function () {
    $host = User::factory()->create();
    $room = Room::factory()->create(['host_user_id' => $host->id, 'status' => RoomStatus::Active]);

    Livewire::actingAs($host)
        ->test('pages::boggle.room', ['code' => $room->code])
        ->call('onRoomStarting');

    $settings = $room->fresh()->gameSession->settings;
    $board = $this->generator->generate($settings['size'], $settings['seed']);

    expect(count($this->solver->solve($board)))->toBeGreaterThanOrEqual(BoardGenerator::MIN_WORDS);
});

test('a fresh solo game and its New Game both deal boards with at least the minimum', function () {
    $page = Livewire::test('pages::boggle.play', ['size' => 4]);
    expect(count($this->solver->solve($page->get('board'))))->toBeGreaterThanOrEqual(BoardGenerator::MIN_WORDS);

    $page->call('resetGame');
    expect(count($this->solver->solve($page->get('board'))))->toBeGreaterThanOrEqual(BoardGenerator::MIN_WORDS);
});

test('an explicit seed (share link, room, challenge) still replays that exact board, even a sparse one', function () {
    $seed = sparseSeed($this->generator, $this->solver);

    expect(Livewire::test('pages::boggle.play', ['size' => 4, 'seed' => $seed])->get('board'))
        ->toBe($this->generator->generate(4, $seed));
});

test('every daily puzzle meets the minimum and is still the same board for everyone', function () {
    // Two weeks of dates is enough that at least one date's raw seed is
    // almost certainly sparse (about 1 in 4 are), exercising the reroll.
    for ($day = 0; $day < 14; $day++) {
        $date = now()->subDays($day)->toDateString();

        $first = Livewire::test('pages::boggle.play', ['dailyDate' => $date])->get('board');
        $second = Livewire::test('pages::boggle.play', ['dailyDate' => $date])->get('board');

        expect($first)->toBe($second)
            ->and(count($this->solver->solve($first)))->toBeGreaterThanOrEqual(BoardGenerator::MIN_WORDS);
    }
});

test('the group pass-and-play game deals a board with at least the minimum', function () {
    $board = Livewire::test('pages::boggle.group')->call('startGame')->get('board');

    expect(count($this->solver->solve($board)))->toBeGreaterThanOrEqual(BoardGenerator::MIN_WORDS);
});
