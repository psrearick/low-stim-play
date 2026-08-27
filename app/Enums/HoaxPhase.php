<?php

namespace App\Enums;

/**
 * Round-level phase. Unlike Punchline's split `PunchlinePhase`/
 * `PunchlineMatchupStatus`, Hoax has no matchup concept -- every round is
 * one shared prompt for the whole table, so a single phase enum covers
 * both the round and its (only) prompt at once. See `docs/specs/hoax.md`
 * section 2.
 */
enum HoaxPhase: string
{
    case Writing = 'writing';
    case Voting = 'voting';
    case RoundReveal = 'round_reveal';
    case GameEnd = 'game_end';
}
