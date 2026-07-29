<?php

namespace App\Services\Stats;

use App\Enums\GameResult;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Collection;

class PlayerStatsService
{
    /**
     * Per-game-type stats for a user, aggregated from their game
     * participation history.
     *
     * @return Collection<int, array{type: GameType, gamesPlayed: int<0, max>, wins: int<0, max>, winRate: int, avgScore: float}>
     */
    public function forUser(User $user): Collection
    {
        return $user->gameParticipants()
            ->with('gameSession')
            ->get()
            ->groupBy(fn (GameParticipant $participant) => $participant->gameSession->type->value)
            ->map(function (Collection $participants, string $type) {
                $gamesPlayed = $participants->count();
                $wins = $participants->where('result', GameResult::Win)->count();

                return [
                    'type' => GameType::from($type),
                    'gamesPlayed' => $gamesPlayed,
                    'wins' => $wins,
                    'winRate' => $gamesPlayed > 0 ? (int) round($wins / $gamesPlayed * 100) : 0,
                    'avgScore' => round((float) $participants->avg('score'), 1),
                ];
            })
            ->values();
    }

    /**
     * @return Collection<int, GameParticipant>
     */
    public function recentGames(User $user, int $limit = 10): Collection
    {
        return $user->gameParticipants()
            ->with('gameSession')
            // `created_at` alone ties for participants recorded in the same
            // second (plausible in practice, not just in fast tests), so
            // `id` breaks the tie deterministically.
            ->latest()
            ->latest('id')
            ->limit($limit)
            ->get();
    }
}
