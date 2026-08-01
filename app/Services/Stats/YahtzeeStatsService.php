<?php

namespace App\Services\Stats;

use App\Enums\GameType;
use App\Enums\YahtzeeCategory;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\Yahtzee\GameEngine;
use Illuminate\Support\Collection;

class YahtzeeStatsService
{
    public function __construct(
        private readonly GameEngine $engine,
    ) {}

    /**
     * Every Yahtzee game the user has played, newest first.
     *
     * @return Collection<int, GameParticipant>
     */
    public function gamesForUser(User $user): Collection
    {
        return $user->gameParticipants()
            ->whereHas('gameSession', fn ($query) => $query->where('type', GameType::Yahtzee))
            ->with('gameSession')
            // See PlayerStatsService::recentGames() -- `id` breaks ties for
            // participants recorded in the same second.
            ->latest()
            ->latest('id')
            ->get();
    }

    /**
     * Per-game stats derived from a participant's stored `scores` (each
     * category's final value) rather than storing the subtotal/bonus/total
     * redundantly -- GameEngine's own pure methods already compute all of
     * these from a plain scores array, exactly like they do during a live
     * game.
     *
     * @return array{scores: array<string, int|null>, upperSubtotal: int, upperBonus: int, lowerSubtotal: int, yahtzeeBonusCount: int, yahtzeeBonusPoints: int}
     */
    public function statsFor(GameParticipant $participant): array
    {
        $scores = $participant->stats['scores'] ?? [];
        $yahtzeeBonusCount = $participant->stats['yahtzee_bonus_count'] ?? 0;

        return [
            'scores' => $scores,
            'upperSubtotal' => $this->engine->upperSubtotal($scores),
            'upperBonus' => $this->engine->upperBonus($scores),
            'lowerSubtotal' => $this->engine->lowerSubtotal($scores),
            'yahtzeeBonusCount' => $yahtzeeBonusCount,
            'yahtzeeBonusPoints' => $this->engine->yahtzeeBonusPoints($yahtzeeBonusCount),
        ];
    }

    /**
     * The best game for each headline metric, paired with the participant
     * it came from so the profile can link straight to it. Metrics with no
     * eligible game (e.g. no participant has ever earned the upper bonus)
     * are simply omitted rather than shown as a misleading zero.
     *
     * @return list<array{label: string, value: string, participant: GameParticipant}>
     */
    public function bestMetrics(User $user): array
    {
        $games = $this->gamesForUser($user);

        if ($games->isEmpty()) {
            return [];
        }

        $decorated = $games->map(fn (GameParticipant $participant) => [
            'participant' => $participant,
            'stats' => $this->statsFor($participant),
        ]);

        $metrics = [];

        $bestScore = $decorated->sortByDesc(fn (array $entry) => $entry['participant']->score)->first();
        $metrics[] = [
            'label' => 'Highest score',
            'value' => (string) $bestScore['participant']->score,
            'participant' => $bestScore['participant'],
        ];

        $withBonus = $decorated->filter(fn (array $entry) => $entry['stats']['yahtzeeBonusCount'] > 0);

        if ($withBonus->isNotEmpty()) {
            $mostBonuses = $withBonus->sortByDesc(fn (array $entry) => $entry['stats']['yahtzeeBonusCount'])->first();
            $metrics[] = [
                'label' => 'Most Yahtzee bonuses',
                'value' => (string) $mostBonuses['stats']['yahtzeeBonusCount'],
                'participant' => $mostBonuses['participant'],
            ];
        }

        $withUpperBonus = $decorated->filter(fn (array $entry) => $entry['stats']['upperBonus'] > 0);

        if ($withUpperBonus->isNotEmpty()) {
            $bestUpper = $withUpperBonus->sortByDesc(fn (array $entry) => $entry['stats']['upperSubtotal'])->first();
            $metrics[] = [
                'label' => 'Best upper section',
                'value' => (string) $bestUpper['stats']['upperSubtotal'],
                'participant' => $bestUpper['participant'],
            ];
        }

        return $metrics;
    }

    /**
     * This user's personal-best score ever recorded in each category,
     * across every Yahtzee game -- Yahtzee's analog to Boggle's "longest/
     * most frequent words" deep-dive list. Categories with no recorded
     * score yet (e.g. a category that was always forced to 0, or a brand
     * new account) are omitted rather than shown as a misleading 0.
     *
     * @return list<array{category: YahtzeeCategory, score: int, participant: GameParticipant}>
     */
    public function bestCategoryScores(User $user): array
    {
        $games = $this->gamesForUser($user);
        $best = [];

        foreach ($games as $participant) {
            $scores = $participant->stats['scores'] ?? [];

            foreach (YahtzeeCategory::all() as $category) {
                $score = $scores[$category->value] ?? null;

                if ($score === null) {
                    continue;
                }

                if (! isset($best[$category->value]) || $score > $best[$category->value]['score']) {
                    $best[$category->value] = ['category' => $category, 'score' => $score, 'participant' => $participant];
                }
            }
        }

        // Stable Ones..Chance order, not insertion order -- matches the
        // scorecard table's own row ordering.
        return collect(YahtzeeCategory::all())
            ->filter(fn (YahtzeeCategory $category) => isset($best[$category->value]))
            ->map(fn (YahtzeeCategory $category) => $best[$category->value])
            ->values()
            ->all();
    }
}
