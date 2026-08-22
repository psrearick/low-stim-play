<?php

namespace App\Services\Words;

/**
 * Ranks a candidate word list by real-world commonness, backed by
 * `storage/app/word_frequency.txt` (word => web-corpus occurrence count,
 * intersected against `storage/app/words.txt` so every entry here is also a
 * word this app already considers valid -- derived from Peter Norvig's
 * `count_1w.txt`, itself aggregated from the Google Web Trillion Word
 * Corpus). Deliberately game-agnostic -- not `Wordle\AnswerPicker`'s own
 * concern -- since Word Blocks' answer-word selection is meant to reuse
 * this same service later (see docs/specs/wordle.md section 3), not just
 * Wordle's.
 */
class CommonWordFilter
{
    /**
     * @var array<string, int> lowercase word => corpus occurrence count
     */
    private readonly array $frequencies;

    public function __construct(?string $frequencyPath = null)
    {
        $this->frequencies = $this->load($frequencyPath ?? storage_path('app/word_frequency.txt'));
    }

    /**
     * Keeps only the words in $words that appear in the frequency corpus
     * at all, ranks them by descending occurrence count, and returns at
     * most $limit of the most common. Order is significant: index 0 is the
     * single most common word in $words.
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    public function mostCommon(array $words, int $limit): array
    {
        $ranked = array_values(array_filter(
            $words,
            fn (string $word): bool => isset($this->frequencies[strtolower($word)]),
        ));

        usort(
            $ranked,
            fn (string $a, string $b): int => $this->frequencies[strtolower($b)] <=> $this->frequencies[strtolower($a)],
        );

        return array_slice($ranked, 0, $limit);
    }

    /**
     * @return array<string, int>
     */
    private function load(string $path): array
    {
        $frequencies = [];
        $handle = fopen($path, 'r');

        while (($line = fgets($handle)) !== false) {
            $parts = explode("\t", trim($line), 2);

            if (count($parts) !== 2) {
                continue;
            }

            [$word, $count] = $parts;
            $frequencies[$word] = (int) $count;
        }

        fclose($handle);

        return $frequencies;
    }
}
