<?php

namespace App\Services\Pips;

use Random\Randomizer;

/**
 * Partitions a board's cells into contiguous regions covering the whole
 * board with no gaps/overlaps -- the "randomized flood-fill/region-growing
 * pass" docs/specs/pips.md section 3 calls for.
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
 */
class RegionGrower
{
    private const MIN_REGION_SIZE = 1;

    private const MAX_REGION_SIZE = 6;

    /**
     * @param  array<int, int>  $partnerOf  cell => its domino partner cell; omit for a purely geometric grow (e.g. tests with no domino structure)
     * @return array<int, list<int>>
     */
    public function grow(Randomizer $randomizer, int $rows, int $cols, array $partnerOf = []): array
    {
        $neighbors = Grid::neighbors($rows, $cols);
        $cellCount = $rows * $cols;

        [$minRegions, $maxRegions] = $this->regionCountBounds($cellCount);
        $regionCount = $randomizer->getInt($minRegions, $maxRegions);

        // array_map('intval', ...) re-asserts the int typing Randomizer::shuffleArray()'s
        // own stub erases (it returns a plain, non-generic `array`) -- values are already
        // guaranteed int coming from range(), this only restores that to static analysis.
        $seeds = array_slice(array_map('intval', $randomizer->shuffleArray(range(0, $cellCount - 1))), 0, $regionCount);

        /** @var array<int, int|null> $owner */
        $owner = array_fill(0, $cellCount, null);
        $regions = [];
        $frontiers = [];
        $caps = [];
        $dormant = [];

        foreach (array_values($seeds) as $regionId => $seedCell) {
            $owner[$seedCell] = $regionId;
            $regions[$regionId] = [$seedCell];
            $frontiers[$regionId] = $neighbors[$seedCell];
            $caps[$regionId] = $randomizer->getInt(self::MIN_REGION_SIZE, self::MAX_REGION_SIZE);
            $dormant[$regionId] = false;
        }

        $this->growRegions($randomizer, $neighbors, $owner, $regions, $frontiers, $caps, $dormant, $regionCount, $partnerOf);
        $this->mopUp($randomizer, $neighbors, $owner, $regions, $partnerOf);

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
     * Round-robin growth: every non-dormant region tries to claim one more
     * cell from its own frontier each pass (a stale, already-claimed
     * candidate -- or one whose domino partner is already in this same
     * region, see this class's own docblock -- is simply discarded, not
     * retried within the same pass; it just costs that region a turn and
     * stays available for another region or mopUp()), reshuffling region
     * order every pass so no region systematically goes first. A region
     * goes dormant once its frontier runs dry or it hits its soft size cap.
     *
     * @param  array<int, list<int>>  $neighbors
     * @param  array<int, int|null>  $owner
     * @param  array<int, list<int>>  $regions
     * @param  array<int, list<int>>  $frontiers
     * @param  array<int, int>  $caps
     * @param  array<int, bool>  $dormant
     * @param  array<int, int>  $partnerOf
     */
    private function growRegions(Randomizer $randomizer, array $neighbors, array &$owner, array &$regions, array &$frontiers, array $caps, array &$dormant, int $regionCount, array $partnerOf): void
    {
        while (in_array(false, $dormant, true)) {
            foreach (array_map('intval', $randomizer->shuffleArray(range(0, $regionCount - 1))) as $regionId) {
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
     * Assigns every cell the growth phase left unclaimed to an
     * already-claimed neighboring region -- a first pass prefers a region
     * that doesn't already hold this cell's domino partner (same
     * anti-splitting preference as growRegions()), then an unrestricted
     * second pass mops up any cell that preference alone could never
     * satisfy, guaranteeing full coverage regardless.
     *
     * @param  array<int, list<int>>  $neighbors
     * @param  array<int, int|null>  $owner
     * @param  array<int, list<int>>  $regions
     * @param  array<int, int>  $partnerOf
     */
    private function mopUp(Randomizer $randomizer, array $neighbors, array &$owner, array &$regions, array $partnerOf): void
    {
        $this->mopUpPass($randomizer, $neighbors, $owner, $regions, $partnerOf, avoidPartnerViolation: true);
        $this->mopUpPass($randomizer, $neighbors, $owner, $regions, $partnerOf, avoidPartnerViolation: false);
    }

    /**
     * @param  array<int, list<int>>  $neighbors
     * @param  array<int, int|null>  $owner
     * @param  array<int, list<int>>  $regions
     * @param  array<int, int>  $partnerOf
     */
    private function mopUpPass(Randomizer $randomizer, array $neighbors, array &$owner, array &$regions, array $partnerOf, bool $avoidPartnerViolation): void
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

                $regionId = $candidateRegions[$randomizer->getInt(0, count($candidateRegions) - 1)];
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
}
