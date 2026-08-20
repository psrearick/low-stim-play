<?php

namespace App\Services\Stats;

use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Collection;

class QuartilesStatsService
{
    /**
     * Every Word Blocks game the user has played, newest first.
     *
     * @return Collection<int, GameParticipant>
     */
    public function gamesForUser(User $user): Collection
    {
        return $user->gameParticipants()
            ->whereHas('gameSession', fn ($query) => $query->where('type', GameType::Quartiles))
            ->with('gameSession')
            // See PlayerStatsService::recentGames() -- `id` breaks ties for
            // participants recorded in the same second.
            ->latest()
            ->latest('id')
            ->get();
    }

    /**
     * Per-game stats derived entirely from a participant's stored `stats`
     * -- same "don't store it redundantly" reasoning as BoggleStatsService.
     *
     * @return array{wordsFound: int, quartilesFound: int, totalValidWords: ?int, percentFound: ?float}
     */
    public function statsFor(GameParticipant $participant): array
    {
        $totalValidWords = $participant->stats['total_valid_words'] ?? null;
        $wordsFound = $participant->stats['words_found'] ?? 0;

        return [
            'wordsFound' => $wordsFound,
            'quartilesFound' => $participant->stats['quartiles_found'] ?? 0,
            'totalValidWords' => $totalValidWords,
            'percentFound' => $totalValidWords ? round($wordsFound / $totalValidWords * 100, 1) : null,
        ];
    }

    /**
     * The best game for each headline metric, paired with the participant
     * it came from -- same "omit a metric nobody's ever earned rather than
     * show a misleading zero" rule every other stats service follows.
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

        $withQuartiles = $decorated->filter(fn (array $entry) => $entry['stats']['quartilesFound'] > 0);

        if ($withQuartiles->isNotEmpty()) {
            $mostQuartiles = $withQuartiles->sortByDesc(fn (array $entry) => $entry['stats']['quartilesFound'])->first();
            $metrics[] = [
                'label' => 'Most Quads found',
                'value' => $mostQuartiles['stats']['quartilesFound'].'/5',
                'participant' => $mostQuartiles['participant'],
            ];
        }

        return $metrics;
    }
}
