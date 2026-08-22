<?php

namespace App\Services\Wordle;

use App\Services\Words\CommonWordFilter;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

class AnswerPicker
{
    public const WORD_LENGTH = 5;

    /**
     * How many of the most common 5-letter words to draw an answer from --
     * roughly NYT's own possible-answer list size (~2,300 words), not the
     * full ~4,800-word frequency-matched subset of `words.txt`'s 5-letter
     * entries: the tail of even that subset still reads as obscure for
     * something that IS the entire puzzle (see docs/specs/wordle.md
     * section 3), unlike a Word Blocks/Boggle find among many.
     */
    private const ANSWER_POOL_SIZE = 1500;

    /**
     * The curated answer pool, built once per instance -- see
     * AppServiceProvider for why this (and CommonWordFilter) are
     * registered as singletons.
     *
     * @var list<string>
     */
    private readonly array $answerPool;

    public function __construct(CommonWordFilter $filter, ?string $wordsPath = null)
    {
        $candidates = $this->loadCandidates($wordsPath ?? storage_path('app/words.txt'));
        $this->answerPool = $filter->mostCommon($candidates, self::ANSWER_POOL_SIZE);

        if ($this->answerPool === []) {
            throw new RuntimeException('No common 5-letter words available to build a Wordle answer pool.');
        }
    }

    /**
     * Passing the same $seed always picks the same word -- used by daily
     * puzzles (`App\Services\Puzzles\DailyPuzzle::seedFor()`); omitted, a
     * genuinely random word is picked instead. The returned word is never
     * meant to be assigned to a rendered Livewire property -- see
     * docs/specs/wordle.md section 3 and pages::wordle.play's own
     * resolveAnswer().
     *
     * @return array{word: string, seed: int}
     */
    public function pick(?int $seed = null): array
    {
        $seed ??= random_int(0, PHP_INT_MAX);
        $randomizer = new Randomizer(new Mt19937($seed));
        $index = $randomizer->getInt(0, count($this->answerPool) - 1);

        return ['word' => $this->answerPool[$index], 'seed' => $seed];
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

            if (mb_strlen($word) === self::WORD_LENGTH) {
                $words[] = $word;
            }
        }

        fclose($handle);

        return $words;
    }
}
