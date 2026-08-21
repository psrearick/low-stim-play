<?php

namespace App\Services\Stats;

use App\Enums\GameMode;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PlayerStatsService
{
    /**
     * Per-game-type stats for a user, aggregated from their game
     * participation history.
     *
     * @return Collection<int, array{type: GameType, gamesPlayed: int<0, max>, wins: int<0, max>, winRate: int, avgScore: float, avgScoreByMode: array{soloGames: int<0, max>, soloAvg: float, onlineGames: int<0, max>, onlineAvg: float}}>
     */
    public function forUser(User $user): Collection
    {
        return $user->gameParticipants()
            ->with('gameSession.participants')
            ->get()
            ->groupBy(fn (GameParticipant $participant) => $participant->gameSession->type->value)
            ->map(fn (Collection $participants, string $type) => $this->statsForType(GameType::from($type), $participants))
            ->values();
    }

    /**
     * @param  Collection<int, GameParticipant>  $participants
     * @return array{type: GameType, gamesPlayed: int<0, max>, wins: int<0, max>, winRate: int, avgScore: float, avgScoreByMode: array{soloGames: int<0, max>, soloAvg: float, onlineGames: int<0, max>, onlineAvg: float}}
     */
    private function statsForType(GameType $type, Collection $participants): array
    {
        $gamesPlayed = $participants->count();

        // "games played"/"avg score" below still cover every game (solo
        // included) -- only the win-rate denominator/numerator get the
        // Boggle-specific restriction, since that's the only number the
        // bug actually distorted.
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
            'avgScoreByMode' => $this->avgScoreByMode($participants),
        ];
    }

    /**
     * Boggle's `score` column doesn't mean the same thing in every mode:
     * solo play accumulates a raw point total, but an online room's score
     * is rewritten by `SharedSessionFinalizer` into an "exclusive" score
     * (a word found by more than one player scores nothing for anyone) --
     * a genuinely different scale, not just a different sample of the same
     * number. Blending both into a single `avgScore` (still computed above
     * for backward compatibility) skews it in either direction depending
     * on how much of a user's play is solo vs. room, so this returns the
     * two averages separately instead -- for every game type, not just
     * Boggle, since the split is harmless (and just as meaningful) even
     * for a type without this particular scoring quirk; the dashboard
     * simply only chooses to render it for Boggle.
     *
     * Each mode's own game count is returned alongside its average
     * (rather than the average alone, `null` when unplayed) deliberately:
     * every field here is a concrete, non-optional `int`/`float`, with no
     * field on this row nullable at all -- avoiding a confirmed Larastan
     * v3.10 false positive ("Template type TValue on class
     * Illuminate\Support\Collection is not covariant", reproduced in
     * complete isolation with a trivial, unrelated `Collection<int,
     * array{a: int, b: ?float}>` shape, so not specific to this app's own
     * types) that fires whenever a `Collection<int, array{...}>`-returning
     * method's row shape has *any* nullable/union field, regardless of
     * how the Collection is built (->map(), ->values(), array_map(),
     * Collection::make(), collect(), a plain `new Collection(...)` all
     * reproduce it identically). A `*Games: int<0, max>` count of `0`
     * unambiguously means "not played" without needing a null at all, so
     * the caller checks the count instead of null-checking the average.
     *
     * @param  Collection<int, GameParticipant>  $participants
     * @return array{soloGames: int<0, max>, soloAvg: float, onlineGames: int<0, max>, onlineAvg: float}
     */
    private function avgScoreByMode(Collection $participants): array
    {
        $solo = $participants->filter(fn (GameParticipant $participant) => $participant->gameSession->mode === GameMode::Solo);
        $online = $participants->filter(fn (GameParticipant $participant) => $participant->gameSession->mode === GameMode::Online);

        return [
            'soloGames' => $solo->count(),
            'soloAvg' => round((float) $solo->avg('score'), 1),
            'onlineGames' => $online->count(),
            'onlineAvg' => round((float) $online->avg('score'), 1),
        ];
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

    /**
     * Every game the user has played, across every game type, newest
     * first -- the same shape/ordering as recentGames(), just paginated
     * instead of capped to a handful, for the "All Games" page.
     *
     * @return LengthAwarePaginator<int, GameParticipant>
     */
    public function allGamesPaginated(User $user, int $perPage = 20)
    {
        return $user->gameParticipants()
            ->with('gameSession')
            ->latest()
            ->latest('id')
            ->paginate($perPage);
    }
}
