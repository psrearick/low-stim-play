<?php

namespace App\Services\Hoax;

/**
 * Canned fallback fills for a writing-deadline timeout (docs/specs/
 * hoax.md section 2) -- no points are ever awarded for one of these, so
 * their only job is to give the round something to display and vote on
 * instead of stalling forever on one slow player. Mirrors
 * `App\Services\Punchline\FillerAnswers` exactly, own Hoax-flavored
 * lines.
 */
class FillerAnswers
{
    private const LINES = [
        'something',
        'I have no idea',
        'a thing, probably',
        '(nothing came to mind)',
        'stuff',
        'you got me',
        'the usual',
        'a mystery',
        'who can say',
        'beats me',
    ];

    /**
     * Deterministic by $index rather than random, matching
     * Punchline\FillerAnswers's own reasoning -- keeps a re-run
     * deterministic and, if this is ever called for more than one missing
     * slot at once, avoids repeating the same line back to back.
     */
    public function pick(int $index): string
    {
        return self::LINES[$index % count(self::LINES)];
    }
}
