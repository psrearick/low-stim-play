<?php

namespace App\Services\Pips;

/**
 * A region's live state given whichever of its cells are currently filled --
 * drives both play-time per-region visual feedback and SolutionSolver's
 * pruning (docs/specs/pips.md section 4's ConstraintChecker).
 */
enum ConstraintStatus
{
    case Satisfied;
    case Broken;
    case Unknown;
}
