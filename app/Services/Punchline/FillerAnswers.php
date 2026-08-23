<?php

namespace App\Services\Punchline;

/**
 * Canned fallback lines for a writing-deadline timeout
 * (docs/specs/punchline.md section 2) -- no default score is ever awarded
 * for one of these, so their only job is to give the matchup/round
 * something to display and vote on instead of stalling forever.
 */
class FillerAnswers
{
    private const LINES = [
        'I got nothing.',
        'The dog ate my punchline.',
        '(This player is still thinking.)',
        'Error 404: joke not found.',
        'I panicked and forgot how words work.',
        'Insert something funnier than this.',
        'My brain buffered too long.',
        'Ask me again in five minutes.',
        'This answer intentionally left blank.',
        '...',
    ];

    /**
     * Deterministic by $index rather than random, so a player who timed
     * out on more than one slot in the same round (round 3's 3 answers)
     * gets a *different* filler line per missing slot instead of the same
     * one repeated.
     */
    public function pick(int $index): string
    {
        return self::LINES[$index % count(self::LINES)];
    }
}
