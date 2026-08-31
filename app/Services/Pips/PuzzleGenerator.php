<?php

namespace App\Services\Pips;

use App\Enums\PipsDifficulty;
use App\Models\PipsPuzzle;
use Illuminate\Database\QueryException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Orchestrates grow shape -> fill -> grow regions -> derive constraints ->
 * (light) duplicate check for a given seed+difficulty, and owns the
 * persisted-puzzle lookup/caching this game needs (docs/specs/pips.md
 * section 4) that no other game in this app requires.
 */
class PuzzleGenerator
{
    /**
     * How many fresh shape-grow attempts to try before giving up -- a shape
     * can pass ShapeGrower's own cheap checkerboard-parity check yet still
     * have no perfect matching at all (a structural "pinch" in the shape),
     * which only BoardFiller's own backtracking search can discover.
     * Regrowing an entirely fresh shape on that rare failure (rather than
     * retrying the same doomed shape) is what docs/DEVLOG.md's Pips entry
     * calls the two-stage grow-then-match approach -- empirically converges
     * in 1-4 attempts across every tier (see that entry for the benchmark).
     */
    private const MAX_SHAPE_ATTEMPTS = 30;

    /**
     * How many freshly-regrown region layouts (on the same filled board) to
     * try before just accepting the last one -- see SolutionSolver's own
     * docblock for why this is a soft preference, not a hard uniqueness
     * gate. Each attempt is cheap (region growth + constraint derivation +
     * a fast local-swap check only, never the expensive exhaustive search),
     * so trying several costs very little, but nothing guarantees any
     * particular attempt comes back free of an obvious duplicate --
     * accepting the last attempt regardless keeps generation fast and
     * always-successful rather than occasionally failing.
     */
    private const MAX_REGION_ATTEMPTS = 20;

    public function __construct(
        private readonly ShapeGrower $shapeGrower,
        private readonly BoardFiller $boardFiller,
        private readonly RegionGrower $regionGrower,
        private readonly ConstraintDeriver $constraintDeriver,
        private readonly RegionColorer $regionColorer,
        private readonly SolutionSolver $solutionSolver,
    ) {}

    /**
     * Looks up the persisted puzzle for $seed+$difficulty, generating and
     * caching one on first request -- every subsequent visitor (including
     * the daily puzzle's own every-visitor-that-day traffic) reuses this
     * same row rather than regenerating.
     */
    public function forSeed(int $seed, PipsDifficulty $difficulty): PipsPuzzle
    {
        $existing = $this->find($seed, $difficulty);

        if ($existing !== null) {
            return $existing;
        }

        $generated = $this->generate($difficulty, $seed);

        try {
            return PipsPuzzle::create([
                'seed' => $seed,
                'difficulty' => $difficulty,
                'board' => $generated['board'],
                'solution' => $generated['solution'],
            ]);
        } catch (QueryException) {
            // Two simultaneous first-requests for the same new seed --
            // whichever lost the unique-constraint race just reads back the
            // winner's row rather than erroring.
            return $this->find($seed, $difficulty) ?? throw new RuntimeException(
                "Pips puzzle insert for seed [{$seed}] difficulty [{$difficulty->value}] failed, but no existing row was found either."
            );
        }
    }

    private function find(int $seed, PipsDifficulty $difficulty): ?PipsPuzzle
    {
        return PipsPuzzle::query()->where('seed', $seed)->where('difficulty', $difficulty)->first();
    }

    /**
     * @return array{
     *     board: array{dimensions: array{rows: int, cols: int}, cells: list<int>, regions: list<array{id: int, cells: list<int>, constraint: array{type: string, value: int|null}, color: int, badge: ?array{row: float, col: float}}>},
     *     solution: array{cellValues: array<int, int>, placements: list<array{cellA: int, cellB: int, valueA: int, valueB: int}>},
     *     seed: int,
     * }
     */
    public function generate(PipsDifficulty $difficulty, ?int $seed = null): array
    {
        $seed ??= random_int(0, PHP_INT_MAX);
        $randomizer = new Randomizer(new Mt19937($seed));

        $maxPip = $difficulty->maxPip();

        [$rows, $cols, $activeCells, $solution] = $this->growTileableShape($randomizer, $difficulty);
        $partnerOf = $this->partnerMap($solution['placements']);

        $regions = [];

        for ($attempt = 0; $attempt < self::MAX_REGION_ATTEMPTS; $attempt++) {
            $regionsRaw = $this->regionGrower->grow($randomizer, $rows, $cols, $activeCells, $partnerOf);
            $constraints = $this->constraintDeriver->derive($regionsRaw, $solution['cellValues'], $randomizer);
            $regions = $this->assembleRegions($regionsRaw, $constraints);

            if ($this->isDegenerate($constraints)) {
                continue;
            }

            if (! $this->solutionSolver->hasObviousDuplicate($regions, $maxPip, $solution)) {
                break;
            }
        }

        $neighbors = Grid::activeNeighbors($rows, $cols, $activeCells);
        $regionCells = array_map(fn (array $region) => $region['cells'], $regions);
        $colors = $this->regionColorer->assign($regionCells, $neighbors, $randomizer);
        $badges = $this->computeBadges($regions, $neighbors, $cols);

        return [
            'board' => [
                'dimensions' => ['rows' => $rows, 'cols' => $cols],
                'cells' => $activeCells,
                'regions' => $this->regionsAsList($regions, $colors, $badges),
            ],
            'solution' => $solution,
            'seed' => $seed,
        ];
    }

    /**
     * Grows a fresh irregular shape and finds a valid domino tiling for it,
     * regrowing an entirely new shape on the rare tiling failure -- see
     * this class's own MAX_SHAPE_ATTEMPTS docblock for why that's the right
     * response rather than retrying the same shape.
     *
     * @return array{0: int, 1: int, 2: list<int>, 3: array{cellValues: array<int, int>, placements: list<array{cellA: int, cellB: int, valueA: int, valueB: int}>}}
     */
    private function growTileableShape(Randomizer $randomizer, PipsDifficulty $difficulty): array
    {
        for ($attempt = 0; $attempt < self::MAX_SHAPE_ATTEMPTS; $attempt++) {
            $shape = $this->shapeGrower->grow($randomizer, $difficulty->rows(), $difficulty->cols(), $difficulty->cellCount());
            $rows = $shape['dimensions']['rows'];
            $cols = $shape['dimensions']['cols'];
            $activeCells = $shape['cells'];

            try {
                $solution = $this->boardFiller->fill($randomizer, $rows, $cols, $activeCells, $difficulty->maxPip());

                return [$rows, $cols, $activeCells, $solution];
            } catch (RuntimeException) {
                continue;
            }
        }

        throw new RuntimeException(
            "Could not grow a tileable {$difficulty->value} Pips shape after ".self::MAX_SHAPE_ATTEMPTS.' attempts.'
        );
    }

    /**
     * Each constrained region's on-board badge anchor -- a fractional
     * board-space position (in cell units, not yet a percentage) the client
     * divides by dimensions.rows/cols to place the badge. A region with no
     * constraint gets no badge at all (docs/specs/pips.md section 3).
     *
     * @param  array<int, array{cells: list<int>, constraint: array{type: string, value: int|null}}>  $regions
     * @param  array<int, list<int>>  $neighbors
     * @return array<int, ?array{row: float, col: float}>
     */
    private function computeBadges(array $regions, array $neighbors, int $cols): array
    {
        $regionOf = [];

        foreach ($regions as $regionId => $region) {
            foreach ($region['cells'] as $cell) {
                $regionOf[$cell] = $regionId;
            }
        }

        $badges = [];

        foreach ($regions as $regionId => $region) {
            $badges[$regionId] = $region['constraint']['type'] === 'none'
                ? null
                : $this->badgeAnchorFor($region['cells'], $regionId, $regionOf, $neighbors, $cols);
        }

        return $badges;
    }

    /**
     * For a region with >= 2 cells: the midpoint of a shared edge between
     * two of its own cells (algebraically the average of their two centers,
     * identical regardless of horizontal/vertical orientation). For a
     * single-cell region: a fixed, deterministic corner of that cell -- a
     * singleton region can be fully surrounded by *other regions* rather
     * than board void, so there's no reliably void-facing edge to prefer.
     *
     * @param  list<int>  $cells
     * @param  array<int, int>  $regionOf
     * @param  array<int, list<int>>  $neighbors
     * @return array{row: float, col: float}
     */
    private function badgeAnchorFor(array $cells, int $regionId, array $regionOf, array $neighbors, int $cols): array
    {
        foreach ($cells as $cell) {
            foreach ($neighbors[$cell] as $neighbor) {
                if ($regionOf[$neighbor] === $regionId) {
                    $row = (intdiv($cell, $cols) + intdiv($neighbor, $cols)) / 2 + 0.5;
                    $col = ($cell % $cols + $neighbor % $cols) / 2 + 0.5;

                    return ['row' => $row, 'col' => $col];
                }
            }
        }

        $cell = $cells[0];

        return ['row' => intdiv($cell, $cols) + 1.0, 'col' => $cell % $cols + 1.0];
    }

    /**
     * @param  list<array{cellA: int, cellB: int, valueA: int, valueB: int}>  $placements
     * @return array<int, int>
     */
    private function partnerMap(array $placements): array
    {
        $map = [];

        foreach ($placements as $placement) {
            $map[$placement['cellA']] = $placement['cellB'];
            $map[$placement['cellB']] = $placement['cellA'];
        }

        return $map;
    }

    /**
     * @param  array<int, list<int>>  $regionsRaw
     * @param  array<int, array{type: string, value: int|null}>  $constraints
     * @return array<int, array{cells: list<int>, constraint: array{type: string, value: int|null}}>
     */
    private function assembleRegions(array $regionsRaw, array $constraints): array
    {
        $regions = [];

        foreach ($regionsRaw as $regionId => $cells) {
            $regions[$regionId] = ['cells' => $cells, 'constraint' => $constraints[$regionId]];
        }

        return $regions;
    }

    /**
     * @param  array<int, array{cells: list<int>, constraint: array{type: string, value: int|null}}>  $regions
     * @param  array<int, int>  $colors
     * @param  array<int, ?array{row: float, col: float}>  $badges
     * @return list<array{id: int, cells: list<int>, constraint: array{type: string, value: int|null}, color: int, badge: ?array{row: float, col: float}}>
     */
    private function regionsAsList(array $regions, array $colors, array $badges): array
    {
        $list = [];

        foreach ($regions as $regionId => $region) {
            $list[] = [
                'id' => $regionId,
                'cells' => $region['cells'],
                'constraint' => $region['constraint'],
                'color' => $colors[$regionId],
                'badge' => $badges[$regionId],
            ];
        }

        return $list;
    }

    /**
     * True only in the astronomically unlikely case every region came back
     * unconstrained -- not a real puzzle at all. ConstraintDeriver's own
     * weighting toward 'sum'/'equal' (see its own docblock) makes this
     * essentially never happen in practice; this exists purely to satisfy
     * "reject a board with no real constraints at all" as an actual floor.
     *
     * @param  array<int, array{type: string, value: int|null}>  $constraints
     */
    private function isDegenerate(array $constraints): bool
    {
        foreach ($constraints as $constraint) {
            if ($constraint['type'] !== 'none') {
                return false;
            }
        }

        return true;
    }
}
