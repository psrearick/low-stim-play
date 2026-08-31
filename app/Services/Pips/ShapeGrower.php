<?php

namespace App\Services\Pips;

use Random\Randomizer;
use RuntimeException;

/**
 * Grows the board's actual playable shape -- an irregular, connected blob of
 * exactly `App\Enums\PipsDifficulty::cellCount()` cells within that tier's
 * (deliberately oversized) rows()/cols() bounding box -- matching the real
 * NYT game's own non-rectangular boards (docs/DEVLOG.md's Pips entry)
 * instead of the dense filled rectangle this game shipped with initially.
 *
 * A single-source randomized frontier growth, the same technique
 * RegionGrower already uses internally, just simplified to one shape instead
 * of many regions and with no domino-partner concern (no tiling exists yet
 * at this stage -- BoardFiller runs afterward, over this shape's own
 * restricted adjacency). Growing shape and tiling together, one domino at a
 * time, was tried first and rejected: it dead-ends far more often (needing a
 * claimed frontier cell *and* one of its unclaimed neighbors simultaneously)
 * and a whole-attempt restart on dead-end throws away all prior progress,
 * whereas growing a plain shape one cell at a time almost never dead-ends
 * given the bounding box's own slack, and reuses BoardFiller's existing,
 * tested MRV backtracking matcher unmodified for the actual tiling step.
 *
 * Checkerboard-balance (equal counts of (row+col) even/odd cells) is a
 * *necessary* condition for a shape to admit any perfect domino tiling at
 * all -- checked here, cheaply, before ever handing the shape to
 * BoardFiller's much more expensive backtracking search.
 */
class ShapeGrower
{
    /**
     * How many fresh (seed cell + growth order) attempts to try before
     * giving up -- each attempt is O(cellCount) and this class's own retry
     * loop is only reached on a dead-end frontier or a checkerboard
     * imbalance, both incidental outcomes of a specific random draw rather
     * than a structural impossibility, so a generous budget costs very
     * little in practice.
     */
    private const MAX_ATTEMPTS = 200;

    /**
     * @return array{cells: list<int>, dimensions: array{rows: int, cols: int}}
     */
    public function grow(Randomizer $randomizer, int $boundRows, int $boundCols, int $targetCellCount): array
    {
        $neighbors = Grid::neighbors($boundRows, $boundCols);

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $seed = $this->centralSeed($randomizer, $boundRows, $boundCols);
            $claimed = $this->attemptGrow($randomizer, $neighbors, $seed, $targetCellCount);

            if ($claimed === null || ! $this->isCheckerboardBalanced($claimed, $boundCols)) {
                continue;
            }

            return $this->reindex($claimed, $boundCols);
        }

        throw new RuntimeException(
            "Could not grow a {$targetCellCount}-cell shape within a {$boundRows}x{$boundCols} bounding box after ".self::MAX_ATTEMPTS.' attempts.'
        );
    }

    /**
     * A seed near the bounding box's own center, jittered by a cell in
     * either direction (clamped back in bounds) -- starting centrally, not
     * in a corner, gives growth room to expand in every direction rather
     * than being funneled by the box's own edges from the very first step.
     */
    private function centralSeed(Randomizer $randomizer, int $boundRows, int $boundCols): int
    {
        $row = intdiv($boundRows, 2) + $randomizer->getInt(-1, 1);
        $col = intdiv($boundCols, 2) + $randomizer->getInt(-1, 1);
        $row = max(0, min($boundRows - 1, $row));
        $col = max(0, min($boundCols - 1, $col));

        return $row * $boundCols + $col;
    }

    /**
     * A single randomized-frontier-growth attempt from $seed. Both $claimed
     * and $frontier are cell-index sets (value always true), giving
     * automatic deduplication and O(1) claim/removal without a separate
     * "already queued" check the way a plain list would need. Returns null
     * the moment the frontier runs dry before reaching $targetCellCount --
     * every neighbor of every claimed cell is itself already claimed, a
     * dead end within this bounding box for this particular seed/order.
     *
     * @param  array<int, list<int>>  $neighbors
     * @return list<int>|null
     */
    private function attemptGrow(Randomizer $randomizer, array $neighbors, int $seed, int $targetCellCount): ?array
    {
        $claimed = [$seed => true];
        $frontier = array_fill_keys($neighbors[$seed], true);

        while (count($claimed) < $targetCellCount) {
            if ($frontier === []) {
                return null;
            }

            $candidates = array_keys($frontier);
            $cell = $candidates[$randomizer->getInt(0, count($candidates) - 1)];
            unset($frontier[$cell]);
            $claimed[$cell] = true;

            foreach ($neighbors[$cell] as $neighbor) {
                if (! isset($claimed[$neighbor])) {
                    $frontier[$neighbor] = true;
                }
            }
        }

        return array_keys($claimed);
    }

    /**
     * @param  list<int>  $cells
     */
    private function isCheckerboardBalanced(array $cells, int $cols): bool
    {
        $even = 0;
        $odd = 0;

        foreach ($cells as $cell) {
            $row = intdiv($cell, $cols);
            $col = $cell % $cols;

            (($row + $col) % 2 === 0) ? $even++ : $odd++;
        }

        return $even === $odd;
    }

    /**
     * Re-indexes a shape grown somewhere inside the (oversized) bounding box
     * into a tight, 0-based box around just its own claimed cells -- the
     * tight box can still contain internal holes (inactive cells amid
     * active ones), a real, valid outcome (e.g. an enclosed interior gap)
     * rather than a bug to prevent.
     *
     * @param  list<int>  $cells
     * @return array{cells: list<int>, dimensions: array{rows: int, cols: int}}
     */
    private function reindex(array $cells, int $cols): array
    {
        $rows = array_map(fn (int $cell) => intdiv($cell, $cols), $cells);
        $colValues = array_map(fn (int $cell) => $cell % $cols, $cells);

        $minRow = min($rows);
        $minCol = min($colValues);
        $tightCols = max($colValues) - $minCol + 1;
        $tightRows = max($rows) - $minRow + 1;

        $tightCells = [];

        foreach ($cells as $cell) {
            $row = intdiv($cell, $cols) - $minRow;
            $col = $cell % $cols - $minCol;
            $tightCells[] = $row * $tightCols + $col;
        }

        sort($tightCells);

        return [
            'cells' => $tightCells,
            'dimensions' => ['rows' => $tightRows, 'cols' => $tightCols],
        ];
    }
}
