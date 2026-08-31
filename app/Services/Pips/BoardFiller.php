<?php

namespace App\Services\Pips;

use Random\Randomizer;
use RuntimeException;

/**
 * Generates one random, valid, fully-filled Pips solution -- the "generate
 * the answer first, then derive constraints" first step docs/specs/pips.md
 * section 3 resolves on. Not part of the spec's own class list (that lists
 * PuzzleGenerator/RegionGrower/ConstraintDeriver/SolutionSolver only) --
 * extracted as its own class anyway since board-filling is a genuinely
 * distinct algorithm (a perfect-matching search) from the rest of
 * PuzzleGenerator's orchestration, mirrored in docs/DEVLOG.md's Pips entry
 * as a minor, non-design-affecting addition.
 *
 * A shape with an even cell count and a balanced checkerboard split (true of
 * every board ShapeGrower hands this class -- see its own docblock) admits a
 * domino tiling with overwhelming probability at these board sizes, and MRV
 * (most-constrained-cell-first) ordering means this essentially never
 * backtracks meaningfully in practice. The node/attempt caps below exist
 * purely to bound worst-case latency in a web request, not because failure
 * is expected -- PuzzleGenerator regrows a fresh shape on the rare exhaustion
 * (docs/DEVLOG.md's Pips entry).
 */
class BoardFiller
{
    private const MAX_MATCH_NODES = 20000;

    private const MAX_FILL_ATTEMPTS = 50;

    /**
     * @param  list<int>  $activeCells  the board's real, possibly-irregular playable cells (App\Services\Pips\ShapeGrower's own output) -- never assumed to be a dense 0..rows*cols-1 rectangle
     * @return array{cellValues: array<int, int>, placements: list<array{cellA: int, cellB: int, valueA: int, valueB: int}>}
     */
    public function fill(Randomizer $randomizer, int $rows, int $cols, array $activeCells, int $maxPip): array
    {
        $neighbors = Grid::activeNeighbors($rows, $cols, $activeCells);

        $pairs = null;

        for ($attempt = 0; $attempt < self::MAX_FILL_ATTEMPTS; $attempt++) {
            $pairs = $this->attemptMatch($randomizer, $activeCells, $neighbors);

            if ($pairs !== null) {
                break;
            }
        }

        if ($pairs === null) {
            throw new RuntimeException("Could not tile a {$rows}x{$cols} shape with dominoes after ".self::MAX_FILL_ATTEMPTS.' attempts.');
        }

        return $this->assignDominoes($randomizer, $pairs, $maxPip);
    }

    /**
     * A single randomized-backtracking attempt at a perfect matching (every
     * cell paired with exactly one adjacent cell). Returns null on a dead
     * end or budget exhaustion, in which case the caller starts a fresh
     * attempt from an empty state.
     *
     * @param  list<int>  $activeCells
     * @param  array<int, list<int>>  $neighbors
     * @return list<array{0: int, 1: int}>|null
     */
    private function attemptMatch(Randomizer $randomizer, array $activeCells, array $neighbors): ?array
    {
        $matched = array_fill_keys($activeCells, false);
        $remaining = array_map('count', $neighbors);
        $pairs = [];
        $nodeCount = 0;

        $solved = $this->matchStep($randomizer, $neighbors, $matched, $remaining, $pairs, $nodeCount);

        return $solved ? $pairs : null;
    }

    /**
     * @param  array<int, list<int>>  $neighbors
     * @param  array<int, bool>  $matched
     * @param  array<int, int>  $remaining
     * @param  list<array{0: int, 1: int}>  $pairs
     */
    private function matchStep(Randomizer $randomizer, array $neighbors, array &$matched, array &$remaining, array &$pairs, int &$nodeCount): bool
    {
        if ($nodeCount > self::MAX_MATCH_NODES) {
            return false;
        }

        $cell = $this->pickMostConstrainedCell($matched, $remaining, $randomizer);

        if ($cell === null) {
            return true;
        }

        if ($remaining[$cell] === 0) {
            return false;
        }

        // array_map('intval', ...) re-asserts the int typing Randomizer::shuffleArray()'s
        // own stub erases (it returns a plain, non-generic `array`) -- values are already
        // guaranteed int coming from $neighbors, this only restores that to static analysis.
        $candidates = array_map(
            'intval',
            $randomizer->shuffleArray(array_values(array_filter($neighbors[$cell], fn (int $n) => ! $matched[$n])))
        );

        foreach ($candidates as $neighbor) {
            $matched[$cell] = true;
            $matched[$neighbor] = true;
            $pairs[] = [$cell, $neighbor];
            $nodeCount++;

            $touched = $this->decrementNeighborCounts($cell, $neighbor, $neighbors, $matched, $remaining);

            if ($this->matchStep($randomizer, $neighbors, $matched, $remaining, $pairs, $nodeCount)) {
                return true;
            }

            array_pop($pairs);
            $matched[$cell] = false;
            $matched[$neighbor] = false;
            $this->restoreNeighborCounts($touched, $remaining);
        }

        return false;
    }

    /**
     * @param  array<int, bool>  $matched
     * @param  array<int, int>  $remaining
     */
    private function pickMostConstrainedCell(array $matched, array $remaining, Randomizer $randomizer): ?int
    {
        $best = null;
        $candidates = [];

        foreach ($matched as $index => $isMatched) {
            if ($isMatched) {
                continue;
            }

            $count = $remaining[$index];

            if ($best === null || $count < $best) {
                $best = $count;
                $candidates = [$index];
            } elseif ($count === $best) {
                $candidates[] = $index;
            }
        }

        if ($candidates === []) {
            return null;
        }

        return $candidates[$randomizer->getInt(0, count($candidates) - 1)];
    }

    /**
     * Decrements the "unmatched neighbors" count for every still-unmatched
     * cell adjacent to either newly-matched cell (they just lost a
     * candidate partner) and returns the touched cells so matchStep() can
     * restore them exactly on backtrack.
     *
     * @param  array<int, list<int>>  $neighbors
     * @param  array<int, bool>  $matched
     * @param  array<int, int>  $remaining
     * @return list<int>
     */
    private function decrementNeighborCounts(int $cellA, int $cellB, array $neighbors, array $matched, array &$remaining): array
    {
        $touched = [];

        foreach ([$cellA, $cellB] as $justMatched) {
            foreach ($neighbors[$justMatched] as $neighbor) {
                if (! $matched[$neighbor]) {
                    $remaining[$neighbor]--;
                    $touched[] = $neighbor;
                }
            }
        }

        return $touched;
    }

    /**
     * @param  list<int>  $touched
     * @param  array<int, int>  $remaining
     */
    private function restoreNeighborCounts(array $touched, array &$remaining): void
    {
        foreach ($touched as $cell) {
            $remaining[$cell]++;
        }
    }

    /**
     * Zips a shuffled full double-N domino set position-wise against the
     * matched cell-pairs (a shuffled fixed-order list against any
     * fixed-order list is already a uniform random bijection), drawing one
     * random bit per pair to decide orientation -- drawn even for doubles,
     * so the randomizer stream shape never depends on how many doubles
     * exist.
     *
     * @param  list<array{0: int, 1: int}>  $pairs
     * @return array{cellValues: array<int, int>, placements: list<array{cellA: int, cellB: int, valueA: int, valueB: int}>}
     */
    private function assignDominoes(Randomizer $randomizer, array $pairs, int $maxPip): array
    {
        $dominoes = $randomizer->shuffleArray($this->fullDominoSet($maxPip));

        $cellValues = [];
        $placements = [];

        foreach ($pairs as $i => [$cellA, $cellB]) {
            [$a, $b] = $dominoes[$i];
            $flip = $randomizer->getInt(0, 1) === 1;
            $valueA = $flip ? $b : $a;
            $valueB = $flip ? $a : $b;

            $cellValues[$cellA] = $valueA;
            $cellValues[$cellB] = $valueB;
            $placements[] = ['cellA' => $cellA, 'cellB' => $cellB, 'valueA' => $valueA, 'valueB' => $valueB];
        }

        ksort($cellValues);

        return ['cellValues' => $cellValues, 'placements' => $placements];
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function fullDominoSet(int $maxPip): array
    {
        $dominoes = [];

        for ($a = 0; $a <= $maxPip; $a++) {
            for ($b = $a; $b <= $maxPip; $b++) {
                $dominoes[] = [$a, $b];
            }
        }

        return $dominoes;
    }
}
