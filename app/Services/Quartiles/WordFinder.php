<?php

namespace App\Services\Quartiles;

use App\Services\Boggle\Solver;

class WordFinder
{
    private const MAX_TILES = 4;

    public function __construct(private readonly Solver $solver) {}

    /**
     * Every valid word reachable by concatenating 1-4 of the given tiles,
     * in any order, paired with the largest tile-count it can be formed
     * with. Reuses Boggle\Solver::isValidWord() as the leaf dictionary
     * check -- no separate word list is loaded here. Only ever called once,
     * at End Game (see docs/specs/quartiles.md section 4) -- real-time
     * submission validity during play is a single isValidWord() call on
     * the concatenated selection, no enumeration needed.
     *
     * @param  array<int, string>  $tileTexts  tile id => lowercase text
     * @return array<string, int> word => max tile-count it can be formed with
     */
    public function findAll(array $tileTexts): array
    {
        $found = [];

        foreach (array_keys($tileTexts) as $startId) {
            $this->extend($tileTexts, [$startId], $tileTexts[$startId], $found);
        }

        return $found;
    }

    /**
     * @param  array<int, string>  $tileTexts
     * @param  list<int>  $usedIds
     * @param  array<string, int>  $found
     */
    private function extend(array $tileTexts, array $usedIds, string $word, array &$found): void
    {
        $tileCount = count($usedIds);

        if ($this->solver->isValidWord($word)) {
            $found[$word] = max($found[$word] ?? 0, $tileCount);
        }

        if ($tileCount >= self::MAX_TILES) {
            return;
        }

        foreach (array_keys($tileTexts) as $nextId) {
            if (in_array($nextId, $usedIds, true)) {
                continue;
            }

            $this->extend($tileTexts, [...$usedIds, $nextId], $word.$tileTexts[$nextId], $found);
        }
    }
}
