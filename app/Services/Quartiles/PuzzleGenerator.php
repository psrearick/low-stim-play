<?php

namespace App\Services\Quartiles;

use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

class PuzzleGenerator
{
    private const GROUP_COUNT = 5;

    private const TILES_PER_GROUP = 4;

    private const MIN_FRAGMENT_LENGTH = 2;

    private const MAX_FRAGMENT_LENGTH = 4;

    private const MIN_WORD_LENGTH = self::TILES_PER_GROUP * self::MIN_FRAGMENT_LENGTH;

    private const MAX_WORD_LENGTH = self::TILES_PER_GROUP * self::MAX_FRAGMENT_LENGTH;

    /**
     * Every dictionary word long enough to split into exactly 4 fragments
     * of 2-4 letters each -- built once per instance. Registered as a
     * singleton (see AppServiceProvider) for the same "only read the
     * ~79k-word file once per worker" reasoning as Boggle\Solver.
     *
     * @var list<string>
     */
    private readonly array $candidateWords;

    public function __construct(?string $wordsPath = null)
    {
        $this->candidateWords = $this->loadCandidates($wordsPath ?? storage_path('app/words.txt'));
    }

    /**
     * Picks 5 distinct dictionary words, splits each into 4 fragments, and
     * shuffles all 20 into an initial grid order. Passing the same `$seed`
     * always produces the same puzzle -- the same reproducibility Boggle's
     * own BoardGenerator offers, unused by anything in v1 but free to keep
     * (see docs/specs/quartiles.md section 4).
     *
     * @return array{
     *     tiles: array<int, array{display: string, match: string, groupIndex: int}>,
     *     groups: list<list<int>>,
     *     tileOrder: list<int>,
     *     seed: int,
     * }
     */
    public function generate(?int $seed = null): array
    {
        $seed ??= random_int(0, PHP_INT_MAX);
        $randomizer = new Randomizer(new Mt19937($seed));

        $tiles = [];
        $groups = [];
        $nextId = 0;

        foreach ($this->pickWords($randomizer) as $groupIndex => $word) {
            $groupTileIds = [];

            foreach ($this->splitWord($word, $randomizer) as $fragment) {
                $tiles[$nextId] = [
                    'display' => strtoupper($fragment),
                    'match' => strtolower($fragment),
                    'groupIndex' => $groupIndex,
                ];
                $groupTileIds[] = $nextId;
                $nextId++;
            }

            $groups[] = $groupTileIds;
        }

        return [
            'tiles' => $tiles,
            'groups' => $groups,
            'tileOrder' => $randomizer->shuffleArray(array_keys($tiles)),
            'seed' => $seed,
        ];
    }

    /**
     * @return list<string>
     */
    private function pickWords(Randomizer $randomizer): array
    {
        if (count($this->candidateWords) < self::GROUP_COUNT) {
            throw new InvalidArgumentException('Not enough candidate words in the dictionary to build a puzzle.');
        }

        $indices = $randomizer->pickArrayKeys($this->candidateWords, self::GROUP_COUNT);

        return array_map(fn (int $index) => $this->candidateWords[$index], $indices);
    }

    /**
     * Splits a word into exactly 4 fragments of 2-4 letters each that
     * concatenate back to the original word. Each fragment's length is
     * randomly grown from a 2-letter floor rather than evenly divided, so
     * the grid doesn't read as mechanically uniform.
     *
     * @return list<string>
     */
    private function splitWord(string $word, Randomizer $randomizer): array
    {
        $lengths = array_fill(0, self::TILES_PER_GROUP, self::MIN_FRAGMENT_LENGTH);
        $remaining = mb_strlen($word) - array_sum($lengths);

        while ($remaining > 0) {
            $growable = array_keys(array_filter(
                $lengths,
                fn (int $length) => $length < self::MAX_FRAGMENT_LENGTH,
            ));

            $index = $growable[$randomizer->getInt(0, count($growable) - 1)];
            $lengths[$index]++;
            $remaining--;
        }

        $fragments = [];
        $offset = 0;

        foreach ($lengths as $length) {
            $fragments[] = mb_substr($word, $offset, $length);
            $offset += $length;
        }

        return $fragments;
    }

    /**
     * @return list<string>
     */
    private function loadCandidates(string $wordsPath): array
    {
        $words = [];
        $handle = fopen($wordsPath, 'r');

        while (($word = fgets($handle)) !== false) {
            $word = trim($word);
            $length = mb_strlen($word);

            if ($length >= self::MIN_WORD_LENGTH && $length <= self::MAX_WORD_LENGTH) {
                $words[] = $word;
            }
        }

        fclose($handle);

        return $words;
    }
}
