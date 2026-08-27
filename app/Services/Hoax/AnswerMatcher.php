<?php

namespace App\Services\Hoax;

/**
 * Decides whether a player's freshly-typed fake answer is actually just
 * the real answer (or a known alternate spelling of it) -- checked at
 * submission time so it can be rejected and the player asked to write
 * something else, rather than silently letting a duplicate-text decoy
 * sit next to the real answer in the reveal (docs/specs/hoax.md section
 * 2). Pure and stateless: no knowledge of ballots, seats, or rounds.
 *
 * Deliberately an exact match after light normalization, not fuzzy/
 * typo-tolerant matching -- a Levenshtein-style "close enough" check
 * risks false positives that would reject a genuinely different fake
 * answer that merely happens to look similar, which is worse than the
 * rare true collision this exists to catch. Known equivalent phrasings
 * (synonyms, digits vs. spelled-out numbers, alternate spellings) are
 * instead handled explicitly via each question's own $alternateAnswers
 * list, authored alongside the question itself.
 */
class AnswerMatcher
{
    /**
     * @param  list<string>  $alternateAnswers
     */
    public function matches(string $submitted, string $answer, array $alternateAnswers = []): bool
    {
        $normalizedSubmitted = $this->normalize($submitted);

        if ($normalizedSubmitted === '') {
            return false;
        }

        foreach ([$answer, ...$alternateAnswers] as $candidate) {
            if ($normalizedSubmitted === $this->normalize($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowercases, collapses whitespace, strips trailing punctuation and
     * thousands-separator commas, and drops a single leading "a"/"an"/
     * "the" -- enough to treat "The Sun", "sun.", and "sun" as the same
     * submission, or "1,200" the same as "1200", without attempting any
     * real stemming/pluralization/typo tolerance.
     */
    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(',', '', $text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = preg_replace('/[.,!?;:]+$/', '', $text) ?? $text;
        $text = preg_replace('/^(a|an|the)\s+/', '', $text) ?? $text;

        return trim($text);
    }
}
