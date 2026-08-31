<?php

namespace App\Services\Pips;

/**
 * A real CSP/backtracking search for a second, different valid domino
 * placement given only a board's public information (regions +
 * constraints), never the generated solution's own cell values as search
 * input (only as the final leaf-vs-leaf distinctness check).
 *
 * **This class's role changed during implementation** (see docs/DEVLOG.md's
 * Pips entry for the full account) **from a hard uniqueness gate to a light
 * sanity check** -- `PuzzleGenerator` calls `hasObviousDuplicate()` (the
 * cheap local-swap pre-pass only) and treats a "yes" as a reason to try
 * regrowing regions again, not as proof the board is broken, and never
 * calls the full exhaustive `check()` from the generation path at all. Full
 * "provably only one board satisfies every region" uniqueness turned out to
 * be structurally out of reach for this puzzle's constraint vocabulary at
 * these board sizes: benchmarked at 0 verified-unique boards out of 500
 * fresh attempts per difficulty, even after two rounds of fixes (see below)
 * intended to make it achievable. `check()` (the full search, still
 * correct and still covered by this class's own unit tests) is kept
 * because it's real, tested code with value beyond generation -- but
 * nothing in the shipped generation path depends on it succeeding.
 *
 * **What "different" means here, and why it isn't a per-cell/per-domino
 * comparison** (the first fix attempted): every constraint type
 * (sum/equal/different/greater/less) is a pure function of a region's
 * *value multiset*, never of which specific cell or domino contributed a
 * given value. That means whenever two dominoes both land inside the same
 * region -- routine at this board size -- swapping which one sits where, or
 * flipping either one's own orientation, changes no region's status at
 * all, even though it changes the exact cell-by-cell grid. A strict
 * cell/domino-identity comparison flags that as "a second solution" and
 * made every board this solver was tested against register as non-unique,
 * including boards where every region was the tightest possible ("sum")
 * constraint. Since this game's own board-completion check is itself
 * already nothing but "every region satisfied" (see PipsPuzzle's own
 * docblock -- never a comparison against a stored answer), two boards the
 * game can never actually tell apart are, for every purpose that matters
 * here, the same solution -- comparisons below are over each region's own
 * sorted value list, not domino identity, orientation, or tiling. This fix
 * alone wasn't enough (RegionGrower also needed to actively avoid ever
 * placing both cells of one domino in the same region -- see its own
 * docblock, the second fix), and even both together left a residual,
 * subtler symmetry (two *different* dominoes straddling the exact same
 * pair of regions can often swap by numeric coincidence, since only 7 pip
 * values exist) common enough that a hard uniqueness gate would make
 * generation fail close to every time.
 *
 * Pure/deterministic -- no Randomizer, so results are reproducible and
 * testable independent of generation's own seed. Search state lives on
 * `$this` for the duration of a single check()/hasObviousDuplicate() call
 * (reset at the top of every call, never reentrant), which keeps the
 * recursive search methods' signatures small instead of threading a dozen
 * by-reference parameters through every call.
 */
class SolutionSolver
{
    /**
     * Hard bounds on search effort -- generation-time only (a puzzle is
     * generated once per seed+difficulty and cached, see PipsPuzzle), so an
     * occasional slow attempt is tolerable; only a truly hanging request is
     * not. Whichever bound is hit first marks the search Inconclusive.
     */
    private const MAX_NODES = 200000;

    private const MAX_SECONDS = 3.0;

    /** @var array<int, list<int>> */
    private array $neighbors;

    /** @var list<int> the board's real, possibly-irregular playable cells */
    private array $activeCells;

    private int $maxPip;

    private int $cellCount;

    /** @var array<int, int> cell => region id */
    private array $regionOf;

    // Region membership is static -- unlike regionFilledValues below, never mutated during search.
    /** @var array<int, list<int>> region id => its cells */
    private array $regionCells;

    /** @var array<int, array{type: string, value: int|null}> */
    private array $regionConstraint;

    /** @var array<int, int|null> cell => currently placed value */
    private array $cellValue;

    /** @var array<string, true> */
    private array $usedDominoes;

    /** @var array<int, list<int>> region id => currently known values */
    private array $regionFilledValues;

    /** @var array<int, int> region id => cells still unfilled */
    private array $regionRemaining;

    /** @var array<int, int> cell => count of still-unfilled neighbors */
    private array $unfilledNeighborCount;

    /** @var list<array{cellA: int, cellB: int, valueA: int, valueB: int}> */
    private array $placements;

    private int $filled;

    private int $nodeCount;

    private float $deadline;

    private bool $budgetExceeded;

    private string $knownSignature;

    public function __construct(private readonly ConstraintChecker $checker) {}

    /**
     * The light sanity check `PuzzleGenerator` actually uses (see this
     * class's own docblock) -- only the cheap local-swap pre-pass, never
     * the expensive exhaustive fallback `check()` falls into below. Returns
     * true the moment any local rearrangement of the known solution's own
     * dominoes is found that stays valid and changes some region's value
     * set; a "false" here is not a proof of uniqueness, just the absence of
     * the single most common and cheapest-to-find kind of ambiguity.
     *
     * @param  array<int, array{cells: list<int>, constraint: array{type: string, value: int|null}}>  $regions
     * @param  array{cellValues: array<int, int>, placements: list<array{cellA: int, cellB: int, valueA: int, valueB: int}>}  $knownSolution
     */
    public function hasObviousDuplicate(array $regions, int $maxPip, array $knownSolution): bool
    {
        $this->maxPip = $maxPip;
        $this->setUpRegions($regions);

        return $this->hasLocalSwap($knownSolution['placements'], $knownSolution['cellValues']);
    }

    /**
     * @param  array<int, array{cells: list<int>, constraint: array{type: string, value: int|null}}>  $regions
     * @param  list<int>  $activeCells  the board's real, possibly-irregular playable cells (App\Services\Pips\ShapeGrower's own output) -- never assumed to be a dense 0..rows*cols-1 rectangle
     * @param  array{cellValues: array<int, int>, placements: list<array{cellA: int, cellB: int, valueA: int, valueB: int}>}  $knownSolution
     */
    public function check(array $regions, int $rows, int $cols, array $activeCells, int $maxPip, array $knownSolution): SolverStatus
    {
        $this->neighbors = Grid::activeNeighbors($rows, $cols, $activeCells);
        $this->activeCells = $activeCells;
        $this->maxPip = $maxPip;
        $this->cellCount = count($activeCells);
        $this->setUpRegions($regions);

        $this->knownSignature = $this->regionValueSignature($knownSolution['cellValues']);

        // A fast pre-pass before falling into the expensive full search:
        // try every local swap of the known solution's own dominoes and see
        // if any still leaves every touched region satisfied *and* actually
        // changes some region's value set. This is what actually catches
        // the common "loose" case quickly -- a region set weak enough to
        // admit a second solution very often admits one reachable by a
        // small local rearrangement, and this is orders of magnitude
        // cheaper than a from-scratch backtracking search finding the same
        // thing by chance.
        if ($this->hasLocalSwap($knownSolution['placements'], $knownSolution['cellValues'])) {
            return SolverStatus::NotUnique;
        }

        $this->cellValue = array_fill_keys($activeCells, null);
        $this->usedDominoes = [];
        $this->unfilledNeighborCount = array_map(count(...), $this->neighbors);
        $this->placements = [];
        $this->filled = 0;
        $this->nodeCount = 0;
        $this->budgetExceeded = false;
        $this->deadline = microtime(true) + self::MAX_SECONDS;

        $foundDifferent = $this->search();

        if ($this->budgetExceeded) {
            return SolverStatus::Inconclusive;
        }

        return $foundDifferent ? SolverStatus::NotUnique : SolverStatus::Unique;
    }

    /**
     * @param  array<int, array{cells: list<int>, constraint: array{type: string, value: int|null}}>  $regions
     */
    private function setUpRegions(array $regions): void
    {
        $this->regionOf = [];
        $this->regionCells = [];
        $this->regionConstraint = [];
        $this->regionFilledValues = [];
        $this->regionRemaining = [];

        foreach ($regions as $regionId => $region) {
            $this->regionCells[$regionId] = $region['cells'];
            $this->regionConstraint[$regionId] = $region['constraint'];
            $this->regionFilledValues[$regionId] = [];
            $this->regionRemaining[$regionId] = count($region['cells']);

            foreach ($region['cells'] as $cell) {
                $this->regionOf[$cell] = $regionId;
            }
        }
    }

    /**
     * @param  list<array{cellA: int, cellB: int, valueA: int, valueB: int}>  $placements
     * @param  array<int, int>  $knownValues  cell => the known solution's own value
     */
    private function hasLocalSwap(array $placements, array $knownValues): bool
    {
        $count = count($placements);

        // Note there's deliberately no "flip a single domino in place"
        // check here: per this class's own docblock, that can never change
        // the orientation-blind signature two boards are compared by, so it
        // could never actually represent a *different* solution to begin
        // with -- only a genuinely different domino occupying a cell-pair
        // (the swap below) counts.

        // Swapping which of two already-used dominoes sits at which
        // position (either orientation for each) -- still uses the exact
        // same multiset of dominoes, so only the touched regions' own
        // constraints need re-checking.
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $pi = $placements[$i];
                $pj = $placements[$j];

                foreach ([[$pj['valueA'], $pj['valueB']], [$pj['valueB'], $pj['valueA']]] as [$newValueAtCellAOfI, $newValueAtCellBOfI]) {
                    foreach ([[$pi['valueA'], $pi['valueB']], [$pi['valueB'], $pi['valueA']]] as [$newValueAtCellAOfJ, $newValueAtCellBOfJ]) {
                        $substitution = [
                            $pi['cellA'] => $newValueAtCellAOfI,
                            $pi['cellB'] => $newValueAtCellBOfI,
                            $pj['cellA'] => $newValueAtCellAOfJ,
                            $pj['cellB'] => $newValueAtCellBOfJ,
                        ];

                        if ($this->substitutionRevealsDifferentSolution($knownValues, $substitution)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Whether hypothetically replacing $substitution's cells (a) leaves
     * every touched region still Satisfied, and (b) actually changes at
     * least one touched region's own *value set* -- (a) alone isn't enough,
     * since a swap that leaves every touched region's value set unchanged
     * (the common same-region-containment case, see this class's own
     * docblock) isn't a genuinely different solution at all. Used only by
     * hasLocalSwap()'s cheap pre-pass, never during the real backtracking
     * search below.
     *
     * @param  array<int, int>  $knownValues  cell => the known solution's own value
     * @param  array<int, int>  $substitution  cell => hypothetical replacement value, for the touched cells only
     */
    private function substitutionRevealsDifferentSolution(array $knownValues, array $substitution): bool
    {
        $touchedRegions = [];

        foreach (array_keys($substitution) as $cell) {
            $touchedRegions[$this->regionOf[$cell]] = true;
        }

        $changed = false;

        foreach (array_keys($touchedRegions) as $regionId) {
            $oldValues = array_map(fn (int $cell) => $knownValues[$cell], $this->regionCells[$regionId]);
            $newValues = array_map(
                fn (int $cell) => $substitution[$cell] ?? $knownValues[$cell],
                $this->regionCells[$regionId],
            );

            if ($this->checker->check($this->regionConstraint[$regionId], $newValues, 0, $this->maxPip) !== ConstraintStatus::Satisfied) {
                return false;
            }

            sort($oldValues);
            sort($newValues);

            if ($oldValues !== $newValues) {
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * @phpstan-impure mutates $this->budgetExceeded (and other search state) directly.
     */
    private function search(): bool
    {
        $this->nodeCount++;

        if ($this->nodeCount > self::MAX_NODES || ($this->nodeCount % 2000 === 0 && microtime(true) > $this->deadline)) {
            $this->budgetExceeded = true;

            return false;
        }

        if ($this->filled === $this->cellCount) {
            /** @var array<int, int> $completedValues */
            $completedValues = $this->cellValue;

            return $this->regionValueSignature($completedValues) !== $this->knownSignature;
        }

        $cell = $this->pickMostConstrainedCell();

        foreach ($this->candidatesFor($cell) as [$neighbor, $valueCell, $valueNeighbor]) {
            $this->apply($cell, $neighbor, $valueCell, $valueNeighbor);
            $found = $this->search();
            $this->undo($cell, $neighbor);

            if ($found) {
                return true;
            }

            if ($this->budgetExceeded) {
                return false;
            }
        }

        return false;
    }

    /**
     * MRV over currently-unfilled geometric neighbors (fewest options
     * first, same heuristic as BoardFiller's own matching search), tied
     * broken toward whichever cell's own region has the fewest cells left
     * -- prioritizing a near-complete region triggers requiredValue()'s
     * propagation shortcut sooner.
     */
    private function pickMostConstrainedCell(): int
    {
        $bestCell = null;
        $bestNeighborCount = null;
        $bestRegionRemaining = null;

        foreach ($this->activeCells as $cell) {
            if ($this->cellValue[$cell] !== null) {
                continue;
            }

            $neighborCount = $this->unfilledNeighborCount[$cell];
            $regionRemaining = $this->regionRemaining[$this->regionOf[$cell]];

            if (
                $bestCell === null
                || $neighborCount < $bestNeighborCount
                || ($neighborCount === $bestNeighborCount && $regionRemaining < $bestRegionRemaining)
            ) {
                $bestCell = $cell;
                $bestNeighborCount = $neighborCount;
                $bestRegionRemaining = $regionRemaining;
            }
        }

        return $bestCell;
    }

    /**
     * @return list<array{0: int, 1: int, 2: int}> each [neighbor, valueForCell, valueForNeighbor]
     */
    private function candidatesFor(int $cell): array
    {
        $candidates = [];

        foreach ($this->neighbors[$cell] as $neighbor) {
            if ($this->cellValue[$neighbor] !== null) {
                continue;
            }

            $requiredForCell = $this->requiredValue($cell);
            $requiredForNeighbor = $this->requiredValue($neighbor);

            if ($requiredForCell !== null && $requiredForNeighbor !== null) {
                if ($this->tryCandidate($cell, $neighbor, $requiredForCell, $requiredForNeighbor)) {
                    $candidates[] = [$neighbor, $requiredForCell, $requiredForNeighbor];
                }

                continue;
            }

            if ($requiredForCell !== null) {
                for ($v = 0; $v <= $this->maxPip; $v++) {
                    if ($this->tryCandidate($cell, $neighbor, $requiredForCell, $v)) {
                        $candidates[] = [$neighbor, $requiredForCell, $v];
                    }
                }

                continue;
            }

            if ($requiredForNeighbor !== null) {
                for ($v = 0; $v <= $this->maxPip; $v++) {
                    if ($this->tryCandidate($cell, $neighbor, $v, $requiredForNeighbor)) {
                        $candidates[] = [$neighbor, $v, $requiredForNeighbor];
                    }
                }

                continue;
            }

            // When $cell and $neighbor share a region, both orientations of
            // a non-double domino are fully equivalent for every purpose
            // the rest of the search cares about -- that region's value
            // *multiset* (and so every later status check against it, and
            // the final orientation-blind signature, see this class's own
            // docblock) comes out identical either way. Trying only the
            // canonical a<=b orientation there halves branching on exactly
            // the placements most likely to be redundant, without ever
            // skipping a genuinely different reachable solution.
            $sameRegion = $this->regionOf[$cell] === $this->regionOf[$neighbor];

            for ($a = 0; $a <= $this->maxPip; $a++) {
                $bStart = $sameRegion ? $a : 0;

                for ($b = $bStart; $b <= $this->maxPip; $b++) {
                    if ($this->tryCandidate($cell, $neighbor, $a, $b)) {
                        $candidates[] = [$neighbor, $a, $b];
                    }
                }
            }
        }

        return $candidates;
    }

    /**
     * A forced value for $cell, when it's provably the only value that
     * could ever go there -- true exactly when $cell is the last unfilled
     * cell in a 'sum' or 'equal' region (the two constraint types where the
     * remaining cells' region math pins down a single number). Returns null
     * for every other constraint type/state, falling back to full
     * enumeration in candidatesFor().
     */
    private function requiredValue(int $cell): ?int
    {
        $regionId = $this->regionOf[$cell];

        if ($this->regionRemaining[$regionId] !== 1) {
            return null;
        }

        $constraint = $this->regionConstraint[$regionId];
        $filled = $this->regionFilledValues[$regionId];

        return match ($constraint['type']) {
            'sum' => $constraint['value'] - array_sum($filled),
            'equal' => $filled === [] ? null : $filled[0],
            default => null,
        };
    }

    private function tryCandidate(int $cell, int $neighbor, int $valueCell, int $valueNeighbor): bool
    {
        if ($valueCell < 0 || $valueCell > $this->maxPip || $valueNeighbor < 0 || $valueNeighbor > $this->maxPip) {
            return false;
        }

        if (isset($this->usedDominoes[$this->dominoKey($valueCell, $valueNeighbor)])) {
            return false;
        }

        $regionCell = $this->regionOf[$cell];
        $regionNeighbor = $this->regionOf[$neighbor];

        if ($regionCell === $regionNeighbor) {
            return $this->regionStatusWithExtra($regionCell, [$valueCell, $valueNeighbor]) !== ConstraintStatus::Broken;
        }

        return $this->regionStatusWithExtra($regionCell, [$valueCell]) !== ConstraintStatus::Broken
            && $this->regionStatusWithExtra($regionNeighbor, [$valueNeighbor]) !== ConstraintStatus::Broken;
    }

    /**
     * @param  list<int>  $extraValues
     */
    private function regionStatusWithExtra(int $regionId, array $extraValues): ConstraintStatus
    {
        return $this->checker->check(
            $this->regionConstraint[$regionId],
            [...$this->regionFilledValues[$regionId], ...$extraValues],
            $this->regionRemaining[$regionId] - count($extraValues),
            $this->maxPip,
        );
    }

    private function apply(int $cell, int $neighbor, int $valueCell, int $valueNeighbor): void
    {
        $this->cellValue[$cell] = $valueCell;
        $this->cellValue[$neighbor] = $valueNeighbor;
        $this->filled += 2;
        $this->usedDominoes[$this->dominoKey($valueCell, $valueNeighbor)] = true;
        $this->placements[] = ['cellA' => $cell, 'cellB' => $neighbor, 'valueA' => $valueCell, 'valueB' => $valueNeighbor];

        foreach ([$cell, $neighbor] as $c) {
            $regionId = $this->regionOf[$c];
            $this->regionFilledValues[$regionId][] = $this->cellValue[$c];
            $this->regionRemaining[$regionId]--;
        }

        foreach ([$cell, $neighbor] as $c) {
            foreach ($this->neighbors[$c] as $n) {
                if ($this->cellValue[$n] === null) {
                    $this->unfilledNeighborCount[$n]--;
                }
            }
        }
    }

    private function undo(int $cell, int $neighbor): void
    {
        foreach ([$cell, $neighbor] as $c) {
            foreach ($this->neighbors[$c] as $n) {
                if ($this->cellValue[$n] === null) {
                    $this->unfilledNeighborCount[$n]++;
                }
            }
        }

        foreach ([$cell, $neighbor] as $c) {
            $regionId = $this->regionOf[$c];
            array_pop($this->regionFilledValues[$regionId]);
            $this->regionRemaining[$regionId]++;
        }

        array_pop($this->placements);
        unset($this->usedDominoes[$this->dominoKey($this->cellValue[$cell], $this->cellValue[$neighbor])]);
        $this->cellValue[$cell] = null;
        $this->cellValue[$neighbor] = null;
        $this->filled -= 2;
    }

    private function dominoKey(int $a, int $b): string
    {
        return $a <= $b ? "{$a}-{$b}" : "{$b}-{$a}";
    }

    /**
     * Each region's own sorted value list, joined in a fixed region-id
     * order -- two complete boards are "the same solution" iff this matches
     * (see this class's own docblock for why domino identity/tiling/
     * orientation are deliberately not part of it).
     *
     * @param  array<int, int>  $cellValues
     */
    private function regionValueSignature(array $cellValues): string
    {
        $parts = [];

        foreach ($this->regionCells as $regionId => $cells) {
            $values = array_map(fn (int $cell) => $cellValues[$cell], $cells);
            sort($values);
            $parts[] = "{$regionId}:".implode(',', $values);
        }

        return implode('|', $parts);
    }
}
