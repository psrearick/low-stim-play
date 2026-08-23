<?php

namespace App\Enums;

/**
 * Round-level phase, distinct from a single matchup's own
 * `PunchlineMatchupStatus` -- the round stays `Writing` until *every*
 * matchup in it has finished writing, but an individual matchup can flip to
 * `voting` (and open for real votes) well before that, the moment its own
 * authors are done. See `docs/specs/punchline.md` section 5.
 */
enum PunchlinePhase: string
{
    case Writing = 'writing';
    case Voting = 'voting';
    case RoundReveal = 'round_reveal';
    case GameEnd = 'game_end';
}
