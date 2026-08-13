<?php

namespace App\Services\Stats;

use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Collection;

class ClearTheDeckStatsService
{
    /**
     * Every Clear the Deck game the user has played, newest first.
     *
     * @return Collection<int, GameParticipant>
     */
    public function gamesForUser(User $user): Collection
    {
        return $user->gameParticipants()
            ->whereHas('gameSession', fn ($query) => $query->where('type', GameType::ClearTheDeck))
            ->with('gameSession')
            // See PlayerStatsService::recentGames() -- `id` breaks ties for
            // participants recorded in the same second.
            ->latest()
            ->latest('id')
            ->get();
    }

    /**
     * @return array{finalScore: int, roundsWon: int}
     */
    public function statsFor(GameParticipant $participant): array
    {
        return [
            'finalScore' => $participant->score,
            'roundsWon' => $participant->stats['rounds_won'] ?? 0,
        ];
    }

    /**
     * The best game for each headline metric, paired with the participant it
     * came from -- mirrors AppleMatchStatsService::bestMetrics() except the
     * headline metric sorts ascending, not descending: unlike every other
     * game's own "best" score, a *lower* final score is better here (see
     * docs/specs/clear-the-deck.md section 4).
     *
     * @return list<array{label: string, value: string, participant: GameParticipant}>
     */
    public function bestMetrics(User $user): array
    {
        $games = $this->gamesForUser($user);

        if ($games->isEmpty()) {
            return [];
        }

        $bestScore = $games->sortBy(fn (GameParticipant $participant) => $participant->score)->first();
        $metrics = [
            ['label' => 'Fewest points', 'value' => (string) $bestScore->score, 'participant' => $bestScore],
        ];

        $decorated = $games->map(fn (GameParticipant $participant) => [
            'participant' => $participant,
            'roundsWon' => $this->statsFor($participant)['roundsWon'],
        ])->filter(fn (array $entry) => $entry['roundsWon'] > 0);

        if ($decorated->isNotEmpty()) {
            $mostRoundsWon = $decorated->sortByDesc('roundsWon')->first();
            $metrics[] = [
                'label' => 'Most rounds won',
                'value' => (string) $mostRoundsWon['roundsWon'],
                'participant' => $mostRoundsWon['participant'],
            ];
        }

        return $metrics;
    }
}
