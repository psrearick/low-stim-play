<?php

namespace App\Services\Quartiles;

use App\Services\Words\CommonWordFilter;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

class PuzzleGenerator
{
    private const GROUP_COUNT = 5;

    private const TILES_PER_GROUP = 4;

    private const MIN_FRAGMENT_LENGTH = 2;

    private const MAX_FRAGMENT_LENGTH = 4;

    private const MIN_WORD_LENGTH = self::TILES_PER_GROUP * self::MIN_FRAGMENT_LENGTH;

    private const MAX_WORD_LENGTH = self::TILES_PER_GROUP * self::MAX_FRAGMENT_LENGTH;

    /**
     * How many of the most common 8-16 letter words to draw the 5 answer
     * words from, out of ~36k that are both in the frequency corpus and
     * not excluded (see CommonWordFilter). Chosen empirically (see
     * docs/DEVLOG.md's Word Blocks section) rather than matching
     * Wordle\AnswerPicker's own 1500 verbatim -- that number was tuned
     * against ~4,800 candidates for a single answer the whole puzzle rests
     * on, a much smaller pool than this game's, and a 1500-word cap here
     * would drop 16-letter candidates to single digits. 12,000 keeps every
     * fragment length (8 through 16 letters) meaningfully represented
     * while still cutting the truly obscure tail (see docs/specs/
     * quartiles.md section 1's own "occasional obscure answer word" note,
     * which this directly addresses).
     */
    private const ANSWER_POOL_SIZE = 12000;

    /**
     * How many times to retry picking/splitting words after landing on a
     * puzzle with a duplicate tile (see generate()'s own docblock) before
     * giving up. Never expected to actually get close to this in practice
     * -- retrying even once resolves the overwhelming majority of
     * collisions, since it's a fresh set of 5 words each time.
     */
    private const MAX_ATTEMPTS = 100;

    /**
     * Every dictionary word long enough to split into exactly 4 fragments
     * of 2-4 letters each, filtered down to the most common (see
     * ANSWER_POOL_SIZE) and screened against the excluded-words list --
     * built once per instance. Registered as a singleton (see
     * AppServiceProvider) for the same "only read the ~79k-word file once
     * per worker" reasoning as Boggle\Solver.
     *
     * @var list<string>
     */
    private readonly array $candidateWords;

    public function __construct(CommonWordFilter $filter, ?string $wordsPath = null)
    {
        $candidates = $this->loadCandidates($wordsPath ?? storage_path('app/words.txt'));
        $this->candidateWords = $filter->mostCommon($candidates, self::ANSWER_POOL_SIZE);

        if ($this->candidateWords === []) {
            throw new RuntimeException('No common words available to build a Word Blocks puzzle.');
        }
    }

    /**
     * Picks 5 distinct dictionary words, splits each into 4 fragments, and
     * shuffles all 20 into an initial grid order. Passing the same `$seed`
     * always produces the same puzzle -- the same reproducibility Boggle's
     * own BoardGenerator offers, unused by anything in v1 but free to keep
     * (see docs/specs/quartiles.md section 4).
     *
     * Every fragment's text is guaranteed unique across all 20 tiles (see
     * attemptUniqueFragments()) -- retries consume more randomness from
     * the same seeded $randomizer rather than starting over with a new
     * one, so a given seed still always produces the same eventual puzzle.
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

        $result = null;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $result = $this->attemptUniqueFragments($randomizer);

            if ($result !== null) {
                break;
            }
        }

        if ($result === null) {
            throw new RuntimeException('Could not generate a puzzle with unique tile text after '.self::MAX_ATTEMPTS.' attempts.');
        }

        return [
            'tiles' => $result['tiles'],
            'groups' => $result['groups'],
            'tileOrder' => $randomizer->shuffleArray(array_keys($result['tiles'])),
            'seed' => $seed,
        ];
    }

    /**
     * One attempt at picking 5 words and splitting them into 20 fragments,
     * returning null the moment any fragment's text collides with one
     * already assigned earlier in this same attempt -- two tiles showing
     * identical text are indistinguishable to a player, so a "Quad" match
     * (checked by exact tile id, not text -- see
     * docs/specs/quartiles.md section 3) can silently fail to register
     * when the player clicks the visually-identical-but-wrong tile.
     * Eliminated at generation time rather than worked around in the UI.
     *
     * @return array{tiles: array<int, array{display: string, match: string, groupIndex: int}>, groups: list<list<int>>}|null
     */
    private function attemptUniqueFragments(Randomizer $randomizer): ?array
    {
        $tiles = [];
        $groups = [];
        $usedTexts = [];
        $nextId = 0;

        foreach ($this->pickWords($randomizer) as $groupIndex => $word) {
            $groupTileIds = [];

            foreach ($this->splitWord($word, $randomizer) as $fragment) {
                $text = strtolower($fragment);

                if (isset($usedTexts[$text])) {
                    return null;
                }

                $usedTexts[$text] = true;
                $tiles[$nextId] = [
                    'display' => strtoupper($fragment),
                    'match' => $text,
                    'groupIndex' => $groupIndex,
                ];
                $groupTileIds[] = $nextId;
                $nextId++;
            }

            $groups[] = $groupTileIds;
        }

        return ['tiles' => $tiles, 'groups' => $groups];
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
