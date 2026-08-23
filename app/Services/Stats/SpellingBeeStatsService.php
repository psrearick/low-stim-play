<?php

namespace App\Services\Stats;

use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\SpellingBee\RankCalculator;
use Illuminate\Support\Collection;

class SpellingBeeStatsService
{
    public function __construct(private readonly RankCalculator $rankCalculator) {}

    /**
     * Every Word Hive game the user has played, newest first.
     *
     * @return Collection<int, GameParticipant>
     */
    public function gamesForUser(User $user): Collection
    {
        return $user->gameParticipants()
            ->whereHas('gameSession', fn ($query) => $query->where('type', GameType::SpellingBee))
            ->with('gameSession')
            // See PlayerStatsService::recentGames() -- `id` breaks ties for
            // participants recorded in the same second.
            ->latest()
            ->latest('id')
            ->get();
    }

    /**
     * Per-game stats derived entirely from a participant's stored `stats`
     * -- same "don't store it redundantly" reasoning as
     * QuartilesStatsService.
     *
     * @return array{wordsFound: int, pangramsFound: int, maxScore: ?int, rank: ?string}
     */
    public function statsFor(GameParticipant $participant): array
    {
        return [
            'wordsFound' => $participant->stats['words_found'] ?? 0,
            'pangramsFound' => $participant->stats['pangrams_found'] ?? 0,
            'maxScore' => $participant->stats['max_score'] ?? null,
            'rank' => $participant->stats['rank'] ?? null,
        ];
    }

    /**
     * The best game for each headline metric, paired with the participant
     * it came from -- same "omit a metric nobody's ever earned rather than
     * show a misleading zero" rule every other stats service follows.
     * "Best rank reached" is compared by the rank's ascending *tier
     * position* on RankCalculator's ladder, not raw score or maxScore --
     * different puzzles have different maxScore totals, so "Amazing" on
     * one puzzle and "Amazing" on another are comparable in a way their
     * raw scores never would be (docs/specs/spelling-bee.md section 4).
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

        $mostWords = $decorated->sortByDesc(fn (array $entry) => $entry['stats']['wordsFound'])->first();
        $metrics[] = [
            'label' => 'Most words found',
            'value' => (string) $mostWords['stats']['wordsFound'],
            'participant' => $mostWords['participant'],
        ];

        $withRank = $decorated->filter(fn (array $entry) => $entry['stats']['rank'] !== null);

        if ($withRank->isNotEmpty()) {
            $bestRank = $withRank->sortByDesc(
                fn (array $entry) => $this->rankCalculator->tierIndex($entry['stats']['rank']) ?? -1
            )->first();

            $metrics[] = [
                'label' => 'Best rank reached',
                'value' => $bestRank['stats']['rank'],
                'participant' => $bestRank['participant'],
            ];
        }

        $withPangrams = $decorated->filter(fn (array $entry) => $entry['stats']['pangramsFound'] > 0);

        if ($withPangrams->isNotEmpty()) {
            $mostPangrams = $withPangrams->sortByDesc(fn (array $entry) => $entry['stats']['pangramsFound'])->first();
            $metrics[] = [
                'label' => 'Most pangrams found',
                'value' => (string) $mostPangrams['stats']['pangramsFound'],
                'participant' => $mostPangrams['participant'],
            ];
        }

        return $metrics;
    }
}
