<?php

namespace App\Services\SpellingBee;

use App\Services\Words\CommonWordFilter;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Picks a pangram (a word using exactly 7 distinct letters) and a center
 * letter, then enumerates every dictionary word of 4+ letters that can be
 * spelled using only those 7 letters (repeats allowed) and containing the
 * center -- see docs/specs/spelling-bee.md section 3.
 */
class PuzzleGenerator
{
    private const MIN_WORD_LENGTH = 4;

    private const REQUIRED_LETTER_COUNT = 7;

    /**
     * A center letter is only accepted once its enumerated word list
     * reaches this size -- a tunable constant, not a rigid product
     * requirement (docs/specs/spelling-bee.md section 3).
     */
    private const MIN_ANSWER_COUNT = 20;

    /**
     * How many pangram candidates to try (each against all 7 of its own
     * letters as center) before giving up -- mirrors
     * Quartiles\PuzzleGenerator::MAX_ATTEMPTS's own retry-cap precedent.
     */
    private const MAX_ATTEMPTS = 100;

    /**
     * Every dictionary word of 4+ letters, already excluded-words-filtered
     * -- the single source both the pangram-candidate pool and every
     * puzzle's own answer-word enumeration are drawn from, so a blocked
     * word can never surface as either.
     *
     * @var list<string>
     */
    private readonly array $answerCandidates;

    /**
     * The subset of $answerCandidates using exactly 7 distinct letters --
     * the only words eligible to seed a puzzle.
     *
     * @var list<string>
     */
    private readonly array $pangramCandidates;

    public function __construct(CommonWordFilter $filter, ?string $wordsPath = null)
    {
        $this->answerCandidates = $filter->excluding(
            $this->loadCandidates($wordsPath ?? storage_path('app/words.txt'))
        );

        $this->pangramCandidates = array_values(array_filter(
            $this->answerCandidates,
            fn (string $word) => count(array_unique(mb_str_split($word))) === self::REQUIRED_LETTER_COUNT,
        ));
    }

    /**
     * Passing the same $seed always produces the same puzzle -- same
     * seeded-Randomizer reproducibility as every other puzzle generator in
     * this app, for DailyPuzzle::seedFor().
     *
     * @return array{letters: list<string>, center: string, words: list<string>, seed: int}
     */
    public function generate(?int $seed = null): array
    {
        if ($this->pangramCandidates === []) {
            throw new InvalidArgumentException('No pangram candidates available to build a Word Hive puzzle.');
        }

        $seed ??= random_int(0, PHP_INT_MAX);
        $randomizer = new Randomizer(new Mt19937($seed));

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $result = $this->attempt($randomizer);

            if ($result !== null) {
                return [...$result, 'seed' => $seed];
            }
        }

        throw new RuntimeException('Could not generate a Word Hive puzzle meeting the minimum word-count threshold after '.self::MAX_ATTEMPTS.' attempts.');
    }

    /**
     * One pangram, tried against each of its own 7 letters as center (in a
     * shuffled order) until one clears the minimum answer-count threshold.
     * Consumes randomness from the same seeded $randomizer whether it
     * succeeds or not, so a given seed always produces the same eventual
     * puzzle regardless of how many candidates were rejected along the way.
     *
     * @return array{letters: list<string>, center: string, words: list<string>}|null
     */
    private function attempt(Randomizer $randomizer): ?array
    {
        $index = $randomizer->getInt(0, count($this->pangramCandidates) - 1);
        $pangram = $this->pangramCandidates[$index];
        $letters = array_values(array_unique(mb_str_split($pangram)));

        foreach ($randomizer->shuffleArray($letters) as $center) {
            $words = $this->enumerate($letters, $center);

            if (count($words) >= self::MIN_ANSWER_COUNT) {
                return ['letters' => $letters, 'center' => $center, 'words' => $words];
            }
        }

        return null;
    }

    /**
     * Public re-derivation of a known letters/center pair's answer list --
     * used by the play page's End Game reveal to recompute the full word
     * list on demand (cheap, ~15ms against the real dictionary) rather
     * than ever holding it in a persisted/public Livewire property, which
     * would let a determined player inspect the payload and see every
     * answer before finding it themselves. Not used by generate() itself,
     * which needs enumerate() during pangram/center selection before
     * either is known to be the puzzle's final choice.
     *
     * @param  list<string>  $letters
     * @return list<string>
     */
    public function wordsFor(array $letters, string $center): array
    {
        return $this->enumerate($letters, $center);
    }

    /**
     * Every word in $this->answerCandidates using only the given 7 letters
     * (any repeats) and containing $center -- computed once per accepted
     * puzzle, the same "expensive, compute once" precedent as Boggle's own
     * solve()/Word Blocks' WordFinder.
     *
     * @param  list<string>  $letters
     * @return list<string>
     */
    private function enumerate(array $letters, string $center): array
    {
        $letterSet = array_flip($letters);
        $found = [];

        foreach ($this->answerCandidates as $word) {
            if (! str_contains($word, $center)) {
                continue;
            }

            if ($this->usesOnly($word, $letterSet)) {
                $found[] = $word;
            }
        }

        return $found;
    }

    /**
     * @param  array<string, int>  $letterSet
     */
    private function usesOnly(string $word, array $letterSet): bool
    {
        foreach (mb_str_split($word) as $char) {
            if (! isset($letterSet[$char])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function loadCandidates(string $wordsPath): array
    {
        $words = [];
        $handle = fopen($wordsPath, 'r');

        while (($word = fgets($handle)) !== false) {
            $word = strtolower(trim($word));

            if (mb_strlen($word) >= self::MIN_WORD_LENGTH) {
                $words[] = $word;
            }
        }

        fclose($handle);

        return $words;
    }
}
