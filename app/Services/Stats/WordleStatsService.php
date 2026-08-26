<?php

namespace App\Services\Stats;

use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Collection;

class WordleStatsService
{
    /**
     * Every Five Letters game the user has played, newest first.
     *
     * @return Collection<int, GameParticipant>
     */
    public function gamesForUser(User $user): Collection
    {
        return $user->gameParticipants()
            ->whereHas('gameSession', fn ($query) => $query->where('type', GameType::Wordle))
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
     * service.
     *
     * @return array{won: bool, guessCount: ?int, hardMode: bool}
     */
    public function statsFor(GameParticipant $participant): array
    {
        return [
            'won' => (bool) ($participant->stats['won'] ?? false),
            'guessCount' => $participant->stats['guess_count'] ?? null,
            'hardMode' => (bool) ($participant->stats['hard_mode'] ?? false),
        ];
    }

    /**
     * The best game for each headline metric, paired with the participant
     * it came from -- same "omit a metric nobody's ever earned rather than
     * show a misleading zero" rule every other stats service follows.
     * "Fewest guesses to win" is the only metric here, and only shown once
     * the user has actually won at least once (guessCount is only ever
     * recorded for a win, see statsFor()).
     *
     * No `GameParticipant.score`-based tile here, unlike every other
     * game's own "Highest score" -- `docs/TODO.md`'s former Task 21
     * changed `score` itself to *be* the guess count (with a
     * worse-than-any-win sentinel for a loss, see finishGame()), so a
     * score-based tile would show the exact same number as "Fewest
     * guesses to win" for anyone who's ever won, and a meaningless
     * sentinel for anyone who hasn't -- redundant in the first case,
     * confusing in the second, with nothing a separate tile would add in
     * either.
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

        $wins = $decorated->filter(fn (array $entry) => $entry['stats']['won']);

        if ($wins->isEmpty()) {
            return [];
        }

        $fewestGuesses = $wins->sortBy(fn (array $entry) => $entry['stats']['guessCount'])->first();

        return [
            [
                'label' => 'Fewest guesses to win',
                'value' => (string) $fewestGuesses['stats']['guessCount'],
                'participant' => $fewestGuesses['participant'],
            ],
        ];
    }
}
