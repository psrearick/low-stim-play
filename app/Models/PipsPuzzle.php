<?php

namespace App\Models;

use App\Enums\PipsDifficulty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A generated-and-cached Pips puzzle, keyed by seed+difficulty -- unlike
 * every other puzzle game in this app (Wordle/Word Blocks/Word Hive all
 * regenerate on every request from a plain seed), Pips' generation is
 * expensive enough (a real CSP uniqueness solver, see SolutionSolver) that
 * it's generated once per seed+difficulty pair and persisted here from then
 * on (docs/specs/pips.md section 4).
 *
 * @property int $seed
 * @property PipsDifficulty $difficulty
 * @property array{dimensions: array{rows: int, cols: int}, regions: list<array{id: int, cells: list<int>, constraint: array{type: string, value: int|null}}>, dominoes: list<string>} $board
 * @property array{cellValues: list<int>, placements: list<array{cellA: int, cellB: int, valueA: int, valueB: int}>} $solution
 */
#[Fillable(['seed', 'difficulty', 'board', 'solution'])]
class PipsPuzzle extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'difficulty' => PipsDifficulty::class,
            'board' => 'array',
            'solution' => 'array',
        ];
    }
}
