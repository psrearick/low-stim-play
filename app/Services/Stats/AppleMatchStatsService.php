<?php

namespace App\Services\Stats;

use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Collection;

class AppleMatchStatsService
{
    /**
     * Every Best Match game the user has played, newest first.
     *
     * @return Collection<int, GameParticipant>
     */
    public function gamesForUser(User $user): Collection
    {
        return $user->gameParticipants()
            ->whereHas('gameSession', fn ($query) => $query->where('type', GameType::AppleMatch))
            ->with('gameSession')
            // See PlayerStatsService::recentGames() -- `id` breaks ties for
            // participants recorded in the same second.
            ->latest()
            ->latest('id')
            ->get();
    }

    /**
     * @return array{greenCardsWon: int, roundsJudged: int}
     */
    public function statsFor(GameParticipant $participant): array
    {
        return [
            'greenCardsWon' => $participant->stats['green_cards_won'] ?? $participant->score,
            'roundsJudged' => $participant->stats['rounds_judged'] ?? 0,
        ];
    }

    /**
     * The best game for each headline metric, paired with the participant
     * it came from -- mirrors FamilyFeudStatsService::bestMetrics() exactly,
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
            ['label' => 'Most green cards won', 'value' => (string) $bestScore->score, 'participant' => $bestScore],
        ];

        $decorated = $games->map(fn (GameParticipant $participant) => [
            'participant' => $participant,
            'roundsJudged' => $this->statsFor($participant)['roundsJudged'],
        ])->filter(fn (array $entry) => $entry['roundsJudged'] > 0);

        if ($decorated->isNotEmpty()) {
            $mostJudged = $decorated->sortByDesc('roundsJudged')->first();
            $metrics[] = [
                'label' => 'Most rounds judged',
                'value' => (string) $mostJudged['roundsJudged'],
                'participant' => $mostJudged['participant'],
            ];
        }

        return $metrics;
    }
}
