<?php

namespace App\Services\Stats;

use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Collection;

class FamilyFeudStatsService
{
    /**
     * Every Family Feud game the user has played, newest first.
     *
     * @return Collection<int, GameParticipant>
     */
    public function gamesForUser(User $user): Collection
    {
        return $user->gameParticipants()
            ->whereHas('gameSession', fn ($query) => $query->where('type', GameType::FamilyFeud))
            ->with('gameSession')
            // See PlayerStatsService::recentGames() -- `id` breaks ties for
            // participants recorded in the same second.
            ->latest()
            ->latest('id')
            ->get();
    }

    /**
     * Per-game stats derived from a participant's stored `stats` --
     * `round_history` is this participant's own team's points per round
     * only (see endGame()'s own note), not the opponent's.
     *
     * @return array{teamIndex: int|null, isCaptain: bool, roundHistory: list<int>}
     */
    public function statsFor(GameParticipant $participant): array
    {
        return [
            'teamIndex' => $participant->stats['team_index'] ?? null,
            'isCaptain' => $participant->stats['is_captain'] ?? false,
            'roundHistory' => $participant->stats['round_history'] ?? [],
        ];
    }

    /**
     * The best game for each headline metric, paired with the participant
     * it came from -- mirrors YahtzeeStatsService::bestMetrics() exactly,
     * including omitting a metric with no eligible game rather than
     * showing a misleading zero.
     *
     * @return list<array{label: string, value: string, participant: GameParticipant}>
     */
    public function bestMetrics(User $user): array
    {
        $games = $this->gamesForUser($user);

        if ($games->isEmpty()) {
            return [];
        }

        $bestScore = $games->sortByDesc(fn (GameParticipant $participant) => $participant->score)->first();
        $metrics = [
            ['label' => 'Highest score', 'value' => (string) $bestScore->score, 'participant' => $bestScore],
        ];

        $decorated = $games->map(fn (GameParticipant $participant) => [
            'participant' => $participant,
            'bestRound' => collect($this->statsFor($participant)['roundHistory'])->max() ?? 0,
        ])->filter(fn (array $entry) => $entry['bestRound'] > 0);

        if ($decorated->isNotEmpty()) {
            $bestRound = $decorated->sortByDesc('bestRound')->first();
            $metrics[] = [
                'label' => 'Biggest single round',
                'value' => (string) $bestRound['bestRound'],
                'participant' => $bestRound['participant'],
            ];
        }

        return $metrics;
    }
}
