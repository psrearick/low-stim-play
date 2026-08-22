<?php

use App\Enums\WordleLetterStatus;
use App\Services\Wordle\GuessEvaluator;

beforeEach(function () {
    $this->evaluator = new GuessEvaluator;
});

test('an exact match is correct in every position', function () {
    $result = $this->evaluator->evaluate('crane', 'crane');

    expect($result)->toBe(array_fill(0, 5, WordleLetterStatus::Correct));
});

test('a letter absent from the answer is marked absent', function () {
    $result = $this->evaluator->evaluate('crane', 'sblty');

    expect($result)->toBe(array_fill(0, 5, WordleLetterStatus::Absent));
});

test('a letter present but in the wrong position is marked present', function () {
    // "ecran" is "crane"'s letters cyclically shifted by one -- since
    // "crane" has 5 distinct letters, this lands every letter in a
    // different position from the original (no coincidental fixed point),
    // so every position should read as present, none correct.
    $result = $this->evaluator->evaluate('crane', 'ecran');

    expect($result)->toBe([
        WordleLetterStatus::Present,
        WordleLetterStatus::Present,
        WordleLetterStatus::Present,
        WordleLetterStatus::Present,
        WordleLetterStatus::Present,
    ]);
});

test('a guess that over-repeats a letter the answer only contains once does not over-credit it', function () {
    // Answer "crane" has exactly one "a" (index 2). Guessing "aabaa" --
    // four a's, none landing on index 2 -- must credit only the first
    // (leftmost) 'a' as present; the other three stay absent.
    $result = $this->evaluator->evaluate('crane', 'aabaa');

    expect($result)->toBe([
        WordleLetterStatus::Present,
        WordleLetterStatus::Absent,
        WordleLetterStatus::Absent,
        WordleLetterStatus::Absent,
        WordleLetterStatus::Absent,
    ]);
});

test('an exact-position match always wins over a present match for the same letter', function () {
    // Answer "kappa": two p's (indices 2-3), two a's (indices 1, 4).
    // Guessing "apple": index 2 lands an exact 'p' match; the guess's
    // other 'p' (index 1) and its first 'a' (index 0) are still credited
    // present against the answer's remaining unmatched p/a.
    $result = $this->evaluator->evaluate('kappa', 'apple');

    expect($result)->toBe([
        WordleLetterStatus::Present,
        WordleLetterStatus::Present,
        WordleLetterStatus::Correct,
        WordleLetterStatus::Absent,
        WordleLetterStatus::Absent,
    ]);
});

test('emptyHardModeRequirements starts with no pinned positions and no minimums', function () {
    expect($this->evaluator->emptyHardModeRequirements(5))->toBe([
        'positions' => [null, null, null, null, null],
        'minCounts' => [],
    ]);
});

test('a correct letter pins its position for future hard-mode requirements', function () {
    $requirements = $this->evaluator->emptyHardModeRequirements(5);
    $result = $this->evaluator->evaluate('crane', 'cabin');
    $requirements = $this->evaluator->mergeHardModeRequirements($requirements, 'cabin', $result);

    expect($requirements['positions'][0])->toBe('c');
});

test('hardModeViolation rejects a guess that abandons a pinned correct position', function () {
    $requirements = $this->evaluator->emptyHardModeRequirements(5);
    $result = $this->evaluator->evaluate('crane', 'cabin');
    $requirements = $this->evaluator->mergeHardModeRequirements($requirements, 'cabin', $result);

    expect($this->evaluator->hardModeViolation($requirements, 'debut'))->toContain('position 1');
});

test('hardModeViolation rejects a guess that drops a previously-present letter', function () {
    $requirements = $this->evaluator->emptyHardModeRequirements(5);
    $result = $this->evaluator->evaluate('crane', 'ecran'); // every letter present
    $requirements = $this->evaluator->mergeHardModeRequirements($requirements, 'ecran', $result);

    expect($this->evaluator->hardModeViolation($requirements, 'blimp'))->not->toBeNull();
});

test('hardModeViolation returns null for a fully compliant guess', function () {
    $requirements = $this->evaluator->emptyHardModeRequirements(5);
    $result = $this->evaluator->evaluate('crane', 'cabin');
    $requirements = $this->evaluator->mergeHardModeRequirements($requirements, 'cabin', $result);

    // 'c' stays pinned in position 0; nothing else was revealed present.
    expect($this->evaluator->hardModeViolation($requirements, 'crane'))->toBeNull();
});

test('mergeHardModeRequirements keeps the highest minimum seen across multiple guesses', function () {
    $requirements = $this->evaluator->emptyHardModeRequirements(5);

    // Answer "sassy" has three s's (indices 0, 2, 3). Guessing "assss"
    // matches 's' correct at indices 2 and 3, and present at index 1 --
    // three s's credited correct/present in this one guess.
    $first = $this->evaluator->evaluate('sassy', 'assss');
    $requirements = $this->evaluator->mergeHardModeRequirements($requirements, 'assss', $first);

    expect($requirements['minCounts']['s'])->toBe(3);

    // "spade" only credits one 's' (an exact match at index 0) -- must not
    // lower the minimum already established by the first guess.
    $second = $this->evaluator->evaluate('sassy', 'spade');
    $requirements = $this->evaluator->mergeHardModeRequirements($requirements, 'spade', $second);

    expect($requirements['minCounts']['s'])->toBe(3);
});
