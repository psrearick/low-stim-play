<?php

namespace App\Services\Stats;

use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Collection;

class FarkleStatsService
{
    /**
     * Every Farkle game the user has played, newest first.
     *
     * @return Collection<int, GameParticipant>
     */
    public function gamesForUser(User $user): Collection
    {
        return $user->gameParticipants()
            ->whereHas('gameSession', fn ($query) => $query->where('type', GameType::Farkle))
            ->with('gameSession')
            // See PlayerStatsService::recentGames() -- `id` breaks ties for
            // participants recorded in the same second.
            ->latest()
            ->latest('id')
            ->get();
    }

    /**
     * Per-game stats derived directly from a participant's stored `stats`
     * -- unlike Yahtzee, there's no per-category breakdown to recompute
     * here, just the raw counters recorded when the game ended.
     *
     * @return array{farkleCount: int, hotDiceCount: int, bestTurnScore: int}
     */
    public function statsFor(GameParticipant $participant): array
    {
        return [
            'farkleCount' => $participant->stats['farkle_count'] ?? 0,
            'hotDiceCount' => $participant->stats['hot_dice_count'] ?? 0,
            'bestTurnScore' => $participant->stats['best_turn_score'] ?? 0,
        ];
    }

    /**
     * The best game for each headline metric, paired with the participant
     * it came from -- mirrors YahtzeeStatsService::bestMetrics()'s own
     * "omit metrics nobody has ever earned" approach.
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

        $bestTurn = $decorated->sortByDesc(fn (array $entry) => $entry['stats']['bestTurnScore'])->first();

        if ($bestTurn['stats']['bestTurnScore'] > 0) {
            $metrics[] = [
                'label' => 'Best single turn',
                'value' => (string) $bestTurn['stats']['bestTurnScore'],
                'participant' => $bestTurn['participant'],
            ];
        }

        $withHotDice = $decorated->filter(fn (array $entry) => $entry['stats']['hotDiceCount'] > 0);

        if ($withHotDice->isNotEmpty()) {
            $mostHotDice = $withHotDice->sortByDesc(fn (array $entry) => $entry['stats']['hotDiceCount'])->first();
            $metrics[] = [
                'label' => 'Most hot dice',
                'value' => (string) $mostHotDice['stats']['hotDiceCount'],
                'participant' => $mostHotDice['participant'],
            ];
        }

        return $metrics;
    }
}
