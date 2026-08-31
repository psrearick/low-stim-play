<?php

namespace App\Services\Pips;

use Random\Randomizer;

/**
 * Assigns each region one of a small palette's color indices via greedy
 * graph coloring, so two regions sharing a border are never rendered with
 * the same fill color -- docs/specs/pips.md section 3's solid-fill region
 * rendering, matching this app's own established precedent for
 * "distinguish N groups with a small hue set" (see
 * resources/css/app.css's `--rival-1..4` tokens, reused here as
 * `--region-1..6`).
 *
 * The four-color theorem guarantees 4 colors are always enough to properly
 * color any planar map (regions on a 2D board are exactly that), but the
 * greedy heuristic below isn't the theorem's own optimal algorithm --
 * PALETTE_SIZE = 6 is a safety margin over that theoretical minimum, backed
 * by a small reshuffle-and-retry loop, rather than a from-scratch
 * four-color implementation.
 */
class RegionColorer
{
    public const PALETTE_SIZE = 6;

    /**
     * How many reshuffled coloring attempts to try before accepting the
     * least-conflicted one found -- an occasional same-color touching pair
     * is a rare, purely cosmetic imperfection (this app already accepts
     * comparable edge cases elsewhere in Pips, e.g. SolutionSolver's own
     * uniqueness sanity check), not worth chasing with an exact algorithm.
     */
    private const MAX_ATTEMPTS = 20;

    /**
     * @param  array<int, list<int>>  $regions  region id => cells
     * @param  array<int, list<int>>  $neighbors  cell => adjacent cells (Grid::activeNeighbors' own output)
     * @return array<int, int> region id => color index (0..PALETTE_SIZE-1)
     */
    public function assign(array $regions, array $neighbors, Randomizer $randomizer): array
    {
        $adjacency = $this->regionAdjacency($regions, $neighbors);

        $best = [];
        $bestConflicts = PHP_INT_MAX;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            [$colors, $conflicts] = $this->attemptColor($regions, $adjacency, $randomizer);

            if ($conflicts === 0) {
                return $colors;
            }

            if ($conflicts < $bestConflicts) {
                $best = $colors;
                $bestConflicts = $conflicts;
            }
        }

        return $best;
    }

    /**
     * Two regions are adjacent only via a strict orthogonal (edge) touch --
     * a diagonal-only touch is not a visual conflict, so including it would
     * needlessly inflate a region's degree and waste palette budget.
     *
     * @param  array<int, list<int>>  $regions
     * @param  array<int, list<int>>  $neighbors
     * @return array<int, list<int>> region id => list of adjacent region ids
     */
    private function regionAdjacency(array $regions, array $neighbors): array
    {
        $regionOf = [];

        foreach ($regions as $regionId => $cells) {
            foreach ($cells as $cell) {
                $regionOf[$cell] = $regionId;
            }
        }

        $adjacency = array_fill_keys(array_keys($regions), []);

        foreach ($regions as $regionId => $cells) {
            foreach ($cells as $cell) {
                foreach ($neighbors[$cell] as $neighbor) {
                    $neighborRegion = $regionOf[$neighbor];

                    if ($neighborRegion !== $regionId && ! in_array($neighborRegion, $adjacency[$regionId], true)) {
                        $adjacency[$regionId][] = $neighborRegion;
                    }
                }
            }
        }

        return $adjacency;
    }

    /**
     * Welsh-Powell-style: color the most-constrained (highest-degree)
     * regions first -- measurably lowers the worst-case color count vs. an
     * arbitrary/insertion order for the same fixed palette size.
     *
     * @param  array<int, list<int>>  $regions
     * @param  array<int, list<int>>  $adjacency
     * @return array{0: array<int, int>, 1: int} [colors, conflict count]
     */
    private function attemptColor(array $regions, array $adjacency, Randomizer $randomizer): array
    {
        $order = array_keys($regions);
        usort($order, fn (int $a, int $b) => count($adjacency[$b]) <=> count($adjacency[$a]));

        $colors = [];
        $conflicts = 0;

        foreach ($order as $regionId) {
            $usedByNeighbors = [];

            foreach ($adjacency[$regionId] as $neighborRegion) {
                if (isset($colors[$neighborRegion])) {
                    $usedByNeighbors[$colors[$neighborRegion]] = true;
                }
            }

            $available = array_values(array_diff(range(0, self::PALETTE_SIZE - 1), array_keys($usedByNeighbors)));

            if ($available === []) {
                // Every palette color is already in use by a neighbor --
                // pick one at random rather than leaving this region
                // uncolored; see this class's own docblock for why a rare
                // same-color touching pair is an acceptable trade-off here.
                $colors[$regionId] = $randomizer->getInt(0, self::PALETTE_SIZE - 1);
                $conflicts++;

                continue;
            }

            $colors[$regionId] = $available[$randomizer->getInt(0, count($available) - 1)];
        }

        return [$colors, $conflicts];
    }
}
