<?php

namespace App\Services\Pips;

/**
 * The result of SolutionSolver::check() -- whether a board's regions/
 * constraints admit exactly one valid domino placement. `Inconclusive`
 * means the search's node/time budget ran out before it could prove either
 * way; PuzzleGenerator treats it exactly like `NotUnique` (retry) since
 * shipping an unverified puzzle is worse than one extra regeneration
 * attempt (docs/specs/pips.md section 3).
 */
enum SolverStatus
{
    case Unique;
    case NotUnique;
    case Inconclusive;
}
