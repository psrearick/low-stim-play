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
}
