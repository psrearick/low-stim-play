<?php

namespace App\Services\Stats;

use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Collection;

class HoaxStatsService
{
    /**
     * Every Hoax game the user has played, newest first.
     *
     * @return Collection<int, GameParticipant>
     */
    public function gamesForUser(User $user): Collection
    {
        return $user->gameParticipants()
            ->whereHas('gameSession', fn ($query) => $query->where('type', GameType::Hoax))
            ->with('gameSession')
            // See PlayerStatsService::recentGames() -- `id` breaks ties for
            // participants recorded in the same second.
            ->latest()
            ->latest('id')
            ->get();
    }

    /**
     * @return array{score: int, pointsByRound: list<int>, truthsFound: int}
     */
    public function statsFor(GameParticipant $participant): array
    {
        return [
            'score' => $participant->score,
            'pointsByRound' => $participant->stats['pointsByRound'] ?? [0, 0, 0, 0, 0],
            'truthsFound' => $participant->stats['truthsFound'] ?? 0,
        ];
    }

    /**
     * The best game for each headline metric, paired with the participant
     * it came from -- mirrors PunchlineStatsService::bestMetrics() exactly,
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
            'truthsFound' => $this->statsFor($participant)['truthsFound'],
        ])->filter(fn (array $entry) => $entry['truthsFound'] > 0);

        if ($decorated->isNotEmpty()) {
            $mostTruths = $decorated->sortByDesc('truthsFound')->first();
            $metrics[] = [
                'label' => 'Most truths found',
                'value' => (string) $mostTruths['truthsFound'],
                'participant' => $mostTruths['participant'],
            ];
        }

        return $metrics;
    }
}
