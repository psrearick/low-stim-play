<?php

namespace App\Services\Stats;

use App\Enums\GameMode;
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
            ->with('gameSession.participants')
            ->get()
            ->groupBy(fn (GameParticipant $participant) => $participant->gameSession->type->value)
            ->map(function (Collection $participants, string $type) {
                $type = GameType::from($type);
                $gamesPlayed = $participants->count();

                // "games played"/"avg score" below still cover every game
                // (solo included) -- only the win-rate denominator/
                // numerator get the Boggle-specific restriction, since
                // that's the only number the bug actually distorted.
                $winRateParticipants = $type === GameType::Boggle
                    ? $this->boggleWinRateEligible($participants)
                    : $participants;

                $winRateGames = $winRateParticipants->count();
                $wins = $winRateParticipants->where('result', GameResult::Win)->count();

                return [
                    'type' => $type,
                    'gamesPlayed' => $gamesPlayed,
                    'wins' => $wins,
                    'winRate' => $winRateGames > 0 ? (int) round($wins / $winRateGames * 100) : 0,
                    'avgScore' => round((float) $participants->avg('score'), 1),
                ];
            })
            ->values();
    }

    /**
     * Boggle is the only game type where `result` can be `null` -- solo/
     * practice play has no opponent to win or lose against, so a solo
     * game was previously still counted in the win-rate *denominator*
     * (as a "game played") while never able to land in the numerator (it
     * can never be a "win"), silently deflating win rate the more solo
     * games someone played. Restricted here to online-room games, and
     * further excluding any session where literally every participant
     * scored 0 (an aborted/never-really-played room, not a real loss).
     *
     * @param  Collection<int, GameParticipant>  $participants
     * @return Collection<int, GameParticipant>
     */
    private function boggleWinRateEligible(Collection $participants): Collection
    {
        return $participants
            ->filter(fn (GameParticipant $participant) => $participant->gameSession->mode === GameMode::Online)
            ->filter(fn (GameParticipant $participant) => $participant->gameSession->participants->contains(
                fn (GameParticipant $sessionParticipant) => $sessionParticipant->score > 0
            ));
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
