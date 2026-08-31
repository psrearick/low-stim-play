<?php

namespace App\Services\Stats;

use App\Enums\GameType;
use App\Enums\PipsDifficulty;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Collection;

class PipsStatsService
{
    /**
     * Every Pips game the user has played, newest first.
     *
     * @return Collection<int, GameParticipant>
     */
    public function gamesForUser(User $user): Collection
    {
        return $user->gameParticipants()
            ->whereHas('gameSession', fn ($query) => $query->where('type', GameType::Pips))
            ->with('gameSession')
            // See PlayerStatsService::recentGames() -- `id` breaks ties for
            // participants recorded in the same second.
            ->latest()
            ->latest('id')
            ->get();
    }

    /**
     * Per-game stats derived entirely from a participant's stored `stats`
     * -- same "don't store it redundantly" reasoning as every other stats
     * service. `difficulty` is read from `stats` (written at endGame()
     * time), not `gameSession->settings`, so this stays self-contained.
     *
     * @return array{solved: bool, durationSeconds: ?int, difficulty: ?string}
     */
    public function statsFor(GameParticipant $participant): array
    {
        return [
            'solved' => $participant->stats['solved'] ?? false,
            'durationSeconds' => $participant->stats['duration_seconds'] ?? null,
            'difficulty' => $participant->stats['difficulty'] ?? null,
        ];
    }

    /**
     * The best game for each headline metric, paired with the participant
     * it came from -- same "omit a metric nobody's ever earned rather than
     * show a misleading zero" rule every other stats service follows.
     * $difficulty restricts the eligible games to one tier, required by
     * `bestMetricsByDifficulty()` rather than blending scores across tiers
     * that aren't comparable to each other (an Easy solve and a Hard solve
     * of the same duration aren't equally impressive) -- same reasoning as
     * `BoggleStatsService::bestMetrics()`'s own $mode split.
     *
     * @return list<array{label: string, value: string, participant: GameParticipant}>
     */
    public function bestMetrics(User $user, ?PipsDifficulty $difficulty = null): array
    {
        $games = $this->gamesForUser($user);

        if ($difficulty !== null) {
            $games = $games->filter(fn (GameParticipant $participant) => $this->statsFor($participant)['difficulty'] === $difficulty->value);
        }

        if ($games->isEmpty()) {
            return [];
        }

        $decorated = $games->map(fn (GameParticipant $participant) => [
            'participant' => $participant,
            'stats' => $this->statsFor($participant),
        ]);

        $metrics = [];

        $solved = $decorated->filter(fn (array $entry) => $entry['stats']['solved'] && $entry['stats']['durationSeconds'] !== null);

        if ($solved->isNotEmpty()) {
            $fastest = $solved->sortBy(fn (array $entry) => $entry['stats']['durationSeconds'])->first();
            $metrics[] = [
                'label' => 'Fastest solve',
                'value' => $this->formatDuration($fastest['stats']['durationSeconds']),
                'participant' => $fastest['participant'],
            ];
        }

        return $metrics;
    }

    /**
     * bestMetrics(), pre-split into the three difficulty tiers
     * (docs/specs/pips.md section 3) -- the shape the profile page renders
     * directly, mirroring `BoggleStatsService::bestMetricsByMode()`'s own
     * solo/room split extended from two categories to three.
     *
     * @return array{easy: list<array{label: string, value: string, participant: GameParticipant}>, medium: list<array{label: string, value: string, participant: GameParticipant}>, hard: list<array{label: string, value: string, participant: GameParticipant}>}
     */
    public function bestMetricsByDifficulty(User $user): array
    {
        return [
            'easy' => $this->bestMetrics($user, PipsDifficulty::Easy),
            'medium' => $this->bestMetrics($user, PipsDifficulty::Medium),
            'hard' => $this->bestMetrics($user, PipsDifficulty::Hard),
        ];
    }

    /**
     * The share of games solved rather than abandoned, at one difficulty
     * (or across all of them if null) -- null, not 0, when the user has no
     * eligible games yet, so the caller can omit the tile instead of
     * showing a misleading 0% (same as `BoggleStatsService::
     * averagePercentFound()`'s own null-when-empty rule).
     */
    public function winRate(User $user, ?PipsDifficulty $difficulty = null): ?float
    {
        $games = $this->gamesForUser($user);

        if ($difficulty !== null) {
            $games = $games->filter(fn (GameParticipant $participant) => $this->statsFor($participant)['difficulty'] === $difficulty->value);
        }

        if ($games->isEmpty()) {
            return null;
        }

        $solvedCount = $games->filter(fn (GameParticipant $participant) => $this->statsFor($participant)['solved'])->count();

        return round($solvedCount / $games->count() * 100, 1);
    }

    /**
     * @return array{easy: ?float, medium: ?float, hard: ?float}
     */
    public function winRateByDifficulty(User $user): array
    {
        return [
            'easy' => $this->winRate($user, PipsDifficulty::Easy),
            'medium' => $this->winRate($user, PipsDifficulty::Medium),
            'hard' => $this->winRate($user, PipsDifficulty::Hard),
        ];
    }

    private function formatDuration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;

        return $minutes > 0 ? "{$minutes}m {$remaining}s" : "{$remaining}s";
    }
}
