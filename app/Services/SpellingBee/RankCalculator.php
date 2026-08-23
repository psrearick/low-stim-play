<?php

namespace App\Services\SpellingBee;

/**
 * Maps a score/max-possible pair to a named tier on a fixed
 * percentage-of-max ladder -- modeled after NYT's own rank ladder shape
 * (percentage-of-max-possible-points thresholds), but with new,
 * non-bee-themed names (docs/specs/spelling-bee.md section 3): dropping
 * the bee/hive-specific NYT branding for this app's "Word Hive" rename
 * meant dropping every bee-themed rank name that leaned on it too, not
 * just the game's own title -- no "Queen Bee," a new "Hive Master"
 * instead, reserved for finding every word (score === maxPossible, since
 * scoring is additive over each word found at most once).
 */
class RankCalculator
{
    /**
     * Ascending by threshold -- percentage cutoffs are a tuning detail to
     * settle once real puzzles are in hand, not a blocking decision.
     *
     * @var list<array{name: string, threshold: float}>
     */
    private const TIERS = [
        ['name' => 'Beginner', 'threshold' => 0.0],
        ['name' => 'Getting Started', 'threshold' => 0.02],
        ['name' => 'Making Progress', 'threshold' => 0.05],
        ['name' => 'Solid', 'threshold' => 0.08],
        ['name' => 'Nice', 'threshold' => 0.15],
        ['name' => 'Great', 'threshold' => 0.25],
        ['name' => 'Amazing', 'threshold' => 0.40],
        ['name' => 'Genius', 'threshold' => 0.70],
        ['name' => 'Hive Master', 'threshold' => 1.0],
    ];

    public function rankFor(int $score, int $maxPossible): string
    {
        $ratio = $maxPossible > 0 ? $score / $maxPossible : 0.0;
        $rank = self::TIERS[0]['name'];

        foreach (self::TIERS as $tier) {
            if ($ratio >= $tier['threshold']) {
                $rank = $tier['name'];
            }
        }

        return $rank;
    }

    /**
     * Every tier name, ascending -- used to compare "best rank reached"
     * across different puzzles by tier *position*, not raw score, since
     * maxPossible varies puzzle to puzzle (docs/specs/spelling-bee.md
     * section 4).
     *
     * @return list<string>
     */
    public function tiers(): array
    {
        return array_column(self::TIERS, 'name');
    }

    /**
     * The ascending position of $rank on the ladder (0 = Beginner), or
     * null if $rank isn't a recognized tier name.
     */
    public function tierIndex(string $rank): ?int
    {
        $index = array_search($rank, $this->tiers(), true);

        return $index === false ? null : $index;
    }
}
