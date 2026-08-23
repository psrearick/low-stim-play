<?php

namespace App\Enums;

/**
 * One matchup's own progress within a round, independent of the round-level
 * `PunchlinePhase` -- see that enum's docblock for how the two interact.
 */
enum PunchlineMatchupStatus: string
{
    case Writing = 'writing';
    case Voting = 'voting';
    case Closed = 'closed';
}
