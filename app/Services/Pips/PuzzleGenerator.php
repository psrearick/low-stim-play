<?php

namespace App\Services\Pips;

use App\Enums\PipsDifficulty;
use App\Models\PipsPuzzle;
use Illuminate\Database\QueryException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Orchestrates fill -> grow regions -> derive constraints -> (light)
 * duplicate check for a given seed+difficulty, and owns the persisted-
 * puzzle lookup/caching this game needs (docs/specs/pips.md section 4) that
 * no other game in this app requires.
 */
class PuzzleGenerator
{
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
        private readonly BoardFiller $boardFiller,
        private readonly RegionGrower $regionGrower,
        private readonly ConstraintDeriver $constraintDeriver,
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
     *     board: array{dimensions: array{rows: int, cols: int}, regions: list<array{id: int, cells: list<int>, constraint: array{type: string, value: int|null}}>},
     *     solution: array{cellValues: array<int, int>, placements: list<array{cellA: int, cellB: int, valueA: int, valueB: int}>},
     *     seed: int,
     * }
     */
    public function generate(PipsDifficulty $difficulty, ?int $seed = null): array
    {
        $seed ??= random_int(0, PHP_INT_MAX);
        $randomizer = new Randomizer(new Mt19937($seed));

        $rows = $difficulty->rows();
        $cols = $difficulty->cols();
        $maxPip = $difficulty->maxPip();

        $solution = $this->boardFiller->fill($randomizer, $rows, $cols, $maxPip);
        $partnerOf = $this->partnerMap($solution['placements']);

        $regions = [];

        for ($attempt = 0; $attempt < self::MAX_REGION_ATTEMPTS; $attempt++) {
            $regionsRaw = $this->regionGrower->grow($randomizer, $rows, $cols, $partnerOf);
            $constraints = $this->constraintDeriver->derive($regionsRaw, $solution['cellValues'], $randomizer);
            $regions = $this->assembleRegions($regionsRaw, $constraints);

            if ($this->isDegenerate($constraints)) {
                continue;
            }

            if (! $this->solutionSolver->hasObviousDuplicate($regions, $maxPip, $solution)) {
                break;
            }
        }

        return [
            'board' => [
                'dimensions' => ['rows' => $rows, 'cols' => $cols],
                'regions' => $this->regionsAsList($regions),
            ],
            'solution' => $solution,
            'seed' => $seed,
        ];
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
     * @return list<array{id: int, cells: list<int>, constraint: array{type: string, value: int|null}}>
     */
    private function regionsAsList(array $regions): array
    {
        $list = [];

        foreach ($regions as $regionId => $region) {
            $list[] = ['id' => $regionId, 'cells' => $region['cells'], 'constraint' => $region['constraint']];
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
