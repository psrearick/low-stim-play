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
 *
 * Also excludes anything in `storage/app/excluded_words.txt`, a hand-curated
 * list of words that are valid and common but off-putting or distressing as
 * a puzzle answer (slurs, profanity, and words tied to violence, slavery,
 * self-harm, etc.) -- commonness alone doesn't screen for that. Exclusion
 * happens before ranking so a blocked word never displaces another
 * candidate's spot in the returned list.
 */
class CommonWordFilter
{
    /**
     * @var array<string, int> lowercase word => corpus occurrence count
     */
    private readonly array $frequencies;

    /**
     * @var array<string, true> lowercase word => present
     */
    private readonly array $excludedWords;

    public function __construct(?string $frequencyPath = null, ?string $excludedWordsPath = null)
    {
        $this->frequencies = $this->loadFrequencies($frequencyPath ?? storage_path('app/word_frequency.txt'));
        $this->excludedWords = $this->loadExcludedWords($excludedWordsPath ?? storage_path('app/excluded_words.txt'));
    }

    /**
     * Keeps only the words in $words that appear in the frequency corpus
     * and are not on the excluded-words list, ranks what's left by
     * descending occurrence count, and returns at most $limit of the most
     * common. Order is significant: index 0 is the single most common word
     * in $words.
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    public function mostCommon(array $words, int $limit): array
    {
        $ranked = array_values(array_filter(
            $words,
            fn (string $word): bool => isset($this->frequencies[strtolower($word)])
                && ! isset($this->excludedWords[strtolower($word)]),
        ));

        usort(
            $ranked,
            fn (string $a, string $b): int => $this->frequencies[strtolower($b)] <=> $this->frequencies[strtolower($a)],
        );

        return array_slice($ranked, 0, $limit);
    }

    /**
     * Drops only the words in $words that are on the excluded-words list --
     * no frequency requirement, no limit, every other word kept as-is
     * (including one absent from the frequency corpus entirely). For a
     * game like Spelling Bee that needs the *full* set of words a given
     * letter combination supports, not a top-N-by-commonness subset --
     * `mostCommon()` above is the wrong shape for that, since it also drops
     * anything missing frequency data and truncates to a limit.
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    public function excluding(array $words): array
    {
        return array_values(array_filter(
            $words,
            fn (string $word): bool => ! isset($this->excludedWords[strtolower($word)]),
        ));
    }

    /**
     * @return array<string, int>
     */
    private function loadFrequencies(string $path): array
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

    /**
     * @return array<string, true>
     */
    private function loadExcludedWords(string $path): array
    {
        $excluded = [];
        $handle = fopen($path, 'r');

        while (($line = fgets($handle)) !== false) {
            $word = strtolower(trim($line));

            if ($word === '' || str_starts_with($word, '#')) {
                continue;
            }

            $excluded[$word] = true;
        }

        fclose($handle);

        return $excluded;
    }
}
