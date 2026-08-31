<?php

namespace App\Services\Pips;

/**
 * Shared cell-indexing/adjacency helpers for the board -- used by
 * BoardFiller, RegionGrower, and SolutionSolver, all of which need the same
 * flat `row * cols + col` indexing and 4-neighbor adjacency over the same
 * grid (docs/specs/pips.md section 4's "cell indexing convention").
 */
class Grid
{
    /**
     * Dense geometric adjacency over every cell in a `rows x cols`
     * rectangle -- used only where the board really is that full rectangle
     * (ShapeGrower's own still-untrimmed growth space). Once a board's real
     * shape is known, activeNeighbors() below is what every other
     * generation-time class uses instead.
     *
     * @return array<int, list<int>>
     */
    public static function neighbors(int $rows, int $cols): array
    {
        $neighbors = [];

        for ($row = 0; $row < $rows; $row++) {
            for ($col = 0; $col < $cols; $col++) {
                $index = $row * $cols + $col;
                $list = [];

                if ($row > 0) {
                    $list[] = $index - $cols;
                }

                if ($row < $rows - 1) {
                    $list[] = $index + $cols;
                }

                if ($col > 0) {
                    $list[] = $index - 1;
                }

                if ($col < $cols - 1) {
                    $list[] = $index + 1;
                }

                $neighbors[$index] = $list;
            }
        }

        return $neighbors;
    }

    /**
     * Geometric adjacency restricted to a board's real, possibly-irregular
     * active cell set -- a `rows x cols` bounding box can contain inactive
     * cells (outside the grown shape, or an enclosed hole inside it) that
     * must never be treated as a neighbor of anything. Every generation-time
     * class other than ShapeGrower's own internal growth uses this, not the
     * dense neighbors() above, once a board's shape is known.
     *
     * @param  list<int>  $activeCells
     * @return array<int, list<int>> keyed only by cells in $activeCells
     */
    public static function activeNeighbors(int $rows, int $cols, array $activeCells): array
    {
        $active = array_fill_keys($activeCells, true);
        $neighbors = [];

        foreach ($activeCells as $index) {
            $row = intdiv($index, $cols);
            $col = $index % $cols;
            $list = [];

            if ($row > 0 && isset($active[$index - $cols])) {
                $list[] = $index - $cols;
            }

            if ($row < $rows - 1 && isset($active[$index + $cols])) {
                $list[] = $index + $cols;
            }

            if ($col > 0 && isset($active[$index - 1])) {
                $list[] = $index - 1;
            }

            if ($col < $cols - 1 && isset($active[$index + 1])) {
                $list[] = $index + 1;
            }

            $neighbors[$index] = $list;
        }

        return $neighbors;
    }
}
