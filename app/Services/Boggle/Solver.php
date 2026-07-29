<?php

namespace App\Services\Boggle;

class Solver
{
    /**
     * Every valid word, keyed by itself, for O(1) lookup.
     *
     * @var array<string, true>
     */
    private readonly array $words;

    /**
     * Every prefix of every valid word, keyed by itself, so the DFS below
     * can prune a path the moment no word could possibly continue it.
     *
     * A flat hashmap of strings uses far less memory than the equivalent
     * nested char-by-char trie (one PHP array per node) would for ~80k
     * words -- PHP's per-array overhead dominates at that scale.
     *
     * @var array<string, true>
     */
    private readonly array $prefixes;

    public function __construct(?string $wordsPath = null)
    {
        [$this->words, $this->prefixes] = $this->buildDictionary($wordsPath ?? storage_path('app/words.txt'));
    }

    /**
     * Find every valid, reachable word on the given board via DFS
     * backtracking over its adjacency graph.
     *
     * @param  array{cells: list<array{match: string}>, neighbors: list<list<int>>}  $board
     * @return list<string> words sorted alphabetically
     */
    public function solve(array $board): array
    {
        $cells = $board['cells'];
        $neighbors = $board['neighbors'];
        $visited = array_fill(0, count($cells), false);
        $found = [];

        $dfs = function (int $index, string $wordSoFar) use (&$dfs, &$found, &$visited, $cells, $neighbors): void {
            $word = $wordSoFar.$cells[$index]['match'];

            if (! isset($this->prefixes[$word])) {
                return;
            }

            if (isset($this->words[$word]) && mb_strlen($word) >= Scorer::MIN_WORD_LENGTH) {
                $found[$word] = true;
            }

            $visited[$index] = true;

            foreach ($neighbors[$index] as $neighborIndex) {
                if (! $visited[$neighborIndex]) {
                    $dfs($neighborIndex, $word);
                }
            }

            $visited[$index] = false;
        };

        foreach (array_keys($cells) as $index) {
            $dfs($index, '');
        }

        $words = array_keys($found);
        sort($words);

        return $words;
    }

    /**
     * Whether a word exists in the dictionary, regardless of board layout.
     */
    public function isValidWord(string $word): bool
    {
        return isset($this->words[strtolower($word)]);
    }

    /**
     * @return array{0: array<string, true>, 1: array<string, true>}
     */
    private function buildDictionary(string $wordsPath): array
    {
        $words = [];
        $prefixes = [];
        $handle = fopen($wordsPath, 'r');

        while (($word = fgets($handle)) !== false) {
            $word = trim($word);

            if ($word === '') {
                continue;
            }

            $words[$word] = true;

            $prefix = '';

            foreach (mb_str_split($word) as $char) {
                $prefix .= $char;
                $prefixes[$prefix] = true;
            }
        }

        fclose($handle);

        return [$words, $prefixes];
    }
}
