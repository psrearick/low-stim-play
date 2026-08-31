<?php

namespace App\Services\Pips;

use Random\Randomizer;

/**
 * Partitions a board's cells into contiguous regions covering the whole
 * board with no gaps/overlaps -- the "randomized flood-fill/region-growing
 * pass" docs/specs/pips.md section 3 calls for. Operates over the board's
 * real, possibly-irregular active cell set (App\Services\Pips\ShapeGrower's
 * own output, via Grid::activeNeighbors()) rather than a dense rectangle --
 * an inactive cell (outside the grown shape, or an enclosed hole inside it)
 * is never a valid neighbor and so never gets claimed by any region.
 *
 * Takes the fill's own domino pairing (`$partnerOf`, cell => its domino
 * partner cell) as a second input alongside pure geometry -- added during
 * implementation (see docs/DEVLOG.md's Pips entry) once SolutionSolver's
 * uniqueness search revealed that a region fully containing both cells of
 * two or more dominoes makes those dominoes freely interchangeable no
 * matter how tight its constraint is (every constraint type is a function
 * of the region's value *multiset*, never of which cell holds which value),
 * which made a uniquely-solvable board nearly unreachable by chance.
 * Growth here actively steers around ever placing both halves of the same
 * domino in one region -- still geometry-only in spirit (it only needs the
 * pairing *structure*, never the fill's actual pip values).
 *
 * Coverage is guaranteed by mopUp()'s own loop condition (it doesn't stop
 * until zero cells are unclaimed -- its second, unrestricted pass is a
 * deliberate escape hatch for the rare deadlock where honoring the
 * domino-split preference any longer would leave a cell unclaimed forever;
 * coverage completeness always wins over that preference). Connectivity is
 * guaranteed by construction, not checked after the fact: a cell is only
 * ever added to a region because it is adjacent to a cell that region
 * already owns (either popped from that region's own frontier queue, or
 * claimed in mopUp() via an already-owned neighbor) -- there is no code
 * path that could disconnect a region's existing footprint once claimed,
 * since claims are permanent.
 *
 * `$maxRegionSize` is a real cap, not just an initial-growth soft target --
 * an irregular, holey board shape's frontiers dry up unevenly (a region
 * boxed into a narrow arm of the polyomino exhausts its own frontier long
 * before the board is covered), which used to leave large contiguous
 * leftover patches for mopUp() to dump wholesale onto whichever single
 * region happened to border them, with no size limit at all (confirmed
 * live: regions up to 25 cells and sum constraints up to 52 on a tier whose
 * highest single pip is 4 -- see docs/DEVLOG.md's Pips entry). growOneRound()
 * is re-invoked with fresh seeds spawned inside any oversized leftover
 * connected component *before* mopUp ever runs, so a big leftover patch
 * becomes its own properly-capped region(s) instead of overflowing an
 * existing one; mopUp()'s own final cleanup (for genuine small scraps only)
 * additionally prefers the smallest under-cap candidate so it doesn't undo
 * that work on its way out.
 */
class RegionGrower
{
    private const MIN_REGION_SIZE = 1;

    /**
     * How many extra growOneRound() passes (each spawning fresh seeds inside
     * whatever oversized leftover patches remain) to allow before handing
     * off to mopUp() regardless -- validated empirically to converge in 1-3
     * rounds in practice; this is purely a worst-case latency bound, not an
     * expected ceiling.
     */
    private const MAX_LEFTOVER_ROUNDS = 10;

    /**
     * @param  list<int>  $activeCells  the board's real, possibly-irregular playable cells (App\Services\Pips\ShapeGrower's own output) -- never assumed to be a dense 0..rows*cols-1 rectangle
     * @param  array<int, int>  $partnerOf  cell => its domino partner cell; omit for a purely geometric grow (e.g. tests with no domino structure)
     * @return array<int, list<int>>
     */
    public function grow(Randomizer $randomizer, int $rows, int $cols, array $activeCells, int $maxRegionSize, array $partnerOf = []): array
    {
        $neighbors = Grid::activeNeighbors($rows, $cols, $activeCells);
        $cellCount = count($activeCells);

        [$minRegions, $maxRegions] = $this->regionCountBounds($cellCount);
        $regionCount = $randomizer->getInt($minRegions, $maxRegions);

        // array_map('intval', ...) re-asserts the int typing Randomizer::shuffleArray()'s
        // own stub erases (it returns a plain, non-generic `array`) -- values are already
        // guaranteed int coming from $activeCells, this only restores that to static analysis.
        $seeds = array_slice(array_map('intval', $randomizer->shuffleArray($activeCells)), 0, $regionCount);

        /** @var array<int, int|null> $owner */
        $owner = array_fill_keys($activeCells, null);
        $regions = [];

        $this->growOneRound($randomizer, $neighbors, $owner, $regions, $partnerOf, $maxRegionSize, array_values($seeds));

        for ($round = 0; $round < self::MAX_LEFTOVER_ROUNDS; $round++) {
            $newSeeds = $this->seedsForOversizedLeftovers($randomizer, $neighbors, $owner, $maxRegionSize);

            if ($newSeeds === []) {
                break;
            }

            $this->growOneRound($randomizer, $neighbors, $owner, $regions, $partnerOf, $maxRegionSize, $newSeeds);
        }

        $this->mopUp($randomizer, $neighbors, $owner, $regions, $partnerOf, $maxRegionSize);

        return $regions;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function regionCountBounds(int $cellCount): array
    {
        $min = max(4, intdiv($cellCount, 6));
        $max = max($min + 1, intdiv($cellCount, 3));

        return [$min, $max];
    }

    /**
     * Round-robin growth from a fresh batch of seed cells, appending new
     * regions (numbered starting from `count($regions)`) rather than
     * replacing any existing ones -- lets grow() call this more than once as
     * oversized leftover patches are discovered. Every non-dormant region
     * tries to claim one more cell from its own frontier each pass (a stale,
     * already-claimed candidate -- or one whose domino partner is already in
     * this same region, see this class's own docblock -- is simply
     * discarded, not retried within the same pass; it just costs that region
     * a turn and stays available for another region or mopUp()), reshuffling
     * region order every pass so no region systematically goes first. A
     * region goes dormant once its frontier runs dry or it hits its own
     * randomly-drawn soft size cap (between 1 and $maxRegionSize).
     *
     * @param  array<int, list<int>>  $neighbors
     * @param  array<int, int|null>  $owner
     * @param  array<int, list<int>>  $regions
     * @param  array<int, int>  $partnerOf
     * @param  list<int>  $seedCells
     */
    private function growOneRound(Randomizer $randomizer, array $neighbors, array &$owner, array &$regions, array $partnerOf, int $maxRegionSize, array $seedCells): void
    {
        $frontiers = [];
        $caps = [];
        $dormant = [];
        $ids = [];

        foreach ($seedCells as $seedCell) {
            $regionId = count($regions);
            $owner[$seedCell] = $regionId;
            $regions[$regionId] = [$seedCell];
            $frontiers[$regionId] = $neighbors[$seedCell];
            $caps[$regionId] = $randomizer->getInt(self::MIN_REGION_SIZE, $maxRegionSize);
            $dormant[$regionId] = false;
            $ids[] = $regionId;
        }

        while (in_array(false, $dormant, true)) {
            foreach (array_map('intval', $randomizer->shuffleArray($ids)) as $regionId) {
                if ($dormant[$regionId]) {
                    continue;
                }

                while ($frontiers[$regionId] !== []) {
                    $cell = array_shift($frontiers[$regionId]);

                    if ($owner[$cell] !== null) {
                        continue;
                    }

                    $partner = $partnerOf[$cell] ?? null;

                    if ($partner !== null && $owner[$partner] === $regionId) {
                        continue;
                    }

                    $owner[$cell] = $regionId;
                    $regions[$regionId][] = $cell;

                    foreach ($neighbors[$cell] as $neighbor) {
                        if ($owner[$neighbor] === null) {
                            $frontiers[$regionId][] = $neighbor;
                        }
                    }

                    break;
                }

                if ($frontiers[$regionId] === [] || count($regions[$regionId]) >= $caps[$regionId]) {
                    $dormant[$regionId] = true;
                }
            }
        }
    }

    /**
     * Finds every still-unclaimed connected component larger than
     * $maxRegionSize and returns fresh seed cells (roughly one per
     * $maxRegionSize cells of that component) to grow proper, capped
     * regions inside it -- the actual fix for mopUp() otherwise dumping a
     * whole leftover patch onto whichever single region happens to border
     * it (see this class's own docblock).
     *
     * @param  array<int, list<int>>  $neighbors
     * @param  array<int, int|null>  $owner
     * @return list<int>
     */
    private function seedsForOversizedLeftovers(Randomizer $randomizer, array $neighbors, array $owner, int $maxRegionSize): array
    {
        $unclaimed = array_keys(array_filter($owner, fn (?int $regionId) => $regionId === null));
        $newSeeds = [];

        foreach ($this->connectedComponents($unclaimed, $neighbors) as $component) {
            if (count($component) <= $maxRegionSize) {
                continue;
            }

            $seedsNeeded = max(1, intdiv(count($component), $maxRegionSize));
            $picked = array_slice(array_map('intval', $randomizer->shuffleArray($component)), 0, $seedsNeeded);
            array_push($newSeeds, ...$picked);
        }

        return $newSeeds;
    }

    /**
     * @param  list<int>  $cells
     * @param  array<int, list<int>>  $neighbors
     * @return list<list<int>>
     */
    private function connectedComponents(array $cells, array $neighbors): array
    {
        $inSet = array_fill_keys($cells, true);
        $visited = [];
        $components = [];

        foreach ($cells as $start) {
            if (isset($visited[$start])) {
                continue;
            }

            $component = [];
            $stack = [$start];

            while ($stack !== []) {
                $cell = array_pop($stack);

                if (isset($visited[$cell])) {
                    continue;
                }

                $visited[$cell] = true;
                $component[] = $cell;

                foreach ($neighbors[$cell] as $neighbor) {
                    if (isset($inSet[$neighbor]) && ! isset($visited[$neighbor])) {
                        $stack[] = $neighbor;
                    }
                }
            }

            $components[] = $component;
        }

        return $components;
    }

    /**
     * Assigns every cell the growth phase left unclaimed to an
     * already-claimed neighboring region -- a first pass prefers a region
     * that doesn't already hold this cell's domino partner (same
     * anti-splitting preference as growOneRound()), then an unrestricted
     * second pass mops up any cell that preference alone could never
     * satisfy, guaranteeing full coverage regardless. By the time this runs,
     * seedsForOversizedLeftovers() has already turned any large leftover
     * patch into its own proper region(s) -- this is only ever handling
     * genuine small scraps.
     *
     * @param  array<int, list<int>>  $neighbors
     * @param  array<int, int|null>  $owner
     * @param  array<int, list<int>>  $regions
     * @param  array<int, int>  $partnerOf
     */
    private function mopUp(Randomizer $randomizer, array $neighbors, array &$owner, array &$regions, array $partnerOf, int $maxRegionSize): void
    {
        $this->mopUpPass($randomizer, $neighbors, $owner, $regions, $partnerOf, $maxRegionSize, avoidPartnerViolation: true);
        $this->mopUpPass($randomizer, $neighbors, $owner, $regions, $partnerOf, $maxRegionSize, avoidPartnerViolation: false);
    }

    /**
     * @param  array<int, list<int>>  $neighbors
     * @param  array<int, int|null>  $owner
     * @param  array<int, list<int>>  $regions
     * @param  array<int, int>  $partnerOf
     */
    private function mopUpPass(Randomizer $randomizer, array $neighbors, array &$owner, array &$regions, array $partnerOf, int $maxRegionSize, bool $avoidPartnerViolation): void
    {
        $unclaimed = array_keys(array_filter($owner, fn (?int $regionId) => $regionId === null));

        while ($unclaimed !== []) {
            $stillUnclaimed = [];

            foreach ($unclaimed as $cell) {
                $candidateRegions = array_values(array_filter(
                    array_map(fn (int $n) => $owner[$n], $neighbors[$cell]),
                    fn (?int $regionId) => $regionId !== null,
                ));

                if ($avoidPartnerViolation) {
                    $partner = $partnerOf[$cell] ?? null;

                    if ($partner !== null) {
                        $candidateRegions = array_values(array_filter(
                            $candidateRegions,
                            fn (int $regionId) => $owner[$partner] !== $regionId,
                        ));
                    }
                }

                if ($candidateRegions === []) {
                    $stillUnclaimed[] = $cell;

                    continue;
                }

                $regionId = $this->smallestCandidate($candidateRegions, $regions, $maxRegionSize, $randomizer);
                $owner[$cell] = $regionId;
                $regions[$regionId][] = $cell;
            }

            if (count($stillUnclaimed) === count($unclaimed)) {
                // No progress this pass -- every still-unclaimed cell's
                // only candidate regions were filtered out by the
                // anti-splitting preference. Stop this phase rather than
                // looping forever; the unrestricted second pass (or, if
                // this already *is* that pass, mopUp()'s own two-call
                // sequence) picks up whatever remains.
                return;
            }

            $unclaimed = $stillUnclaimed;
        }
    }

    /**
     * Prefers a candidate region still under $maxRegionSize (falling back to
     * every candidate if none qualify -- coverage always wins), then the
     * smallest among whichever pool applies, breaking ties randomly so one
     * region doesn't systematically win every tie. This is what keeps a
     * chain of leftover scraps from snowballing onto a single region the
     * way a uniform-random choice among candidates otherwise would.
     *
     * @param  list<int>  $candidateRegions
     * @param  array<int, list<int>>  $regions
     */
    private function smallestCandidate(array $candidateRegions, array $regions, int $maxRegionSize, Randomizer $randomizer): int
    {
        $underCap = array_values(array_filter($candidateRegions, fn (int $regionId) => count($regions[$regionId]) < $maxRegionSize));
        $pool = $underCap !== [] ? $underCap : $candidateRegions;

        $smallestSize = min(array_map(fn (int $regionId) => count($regions[$regionId]), $pool));
        $tied = array_values(array_filter($pool, fn (int $regionId) => count($regions[$regionId]) === $smallestSize));

        return $tied[$randomizer->getInt(0, count($tied) - 1)];
    }
}
