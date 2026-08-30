<?php

namespace App\Services\Stats;

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Collection;

class BoggleStatsService
{
    /**
     * Every Boggle game the user has played, newest first.
     *
     * @return Collection<int, GameParticipant>
     */
    public function gamesForUser(User $user): Collection
    {
        return $user->gameParticipants()
            ->whereHas('gameSession', fn ($query) => $query->where('type', GameType::Boggle))
            ->with('gameSession')
            // See PlayerStatsService::recentGames() -- `id` breaks ties for
            // participants recorded in the same second.
            ->latest()
            ->latest('id')
            ->get();
    }

    /**
     * Per-game stats derived entirely from a participant's stored
     * `found_words` (and, when present, `total_valid_words`) rather than
     * stored redundantly -- this keeps it correct even for participants
     * recorded before a metric here existed.
     *
     * @return array{wordsFound: int<0, max>, longestWord: ?string, longestWordLength: int<0, max>, byLength: array<int, int<0, max>>, totalValidWords: ?int, percentFound: ?float, longWordPoints: int<0, max>}
     */
    public function statsFor(GameParticipant $participant): array
    {
        $foundWords = $participant->stats['found_words'] ?? [];
        $words = array_keys($foundWords);

        $byLength = [];
        $longestWord = null;
        $longWordPoints = 0;

        foreach ($foundWords as $word => $points) {
            $length = mb_strlen($word);
            $byLength[$length] = ($byLength[$length] ?? 0) + 1;

            if ($longestWord === null || $length > mb_strlen($longestWord)) {
                $longestWord = $word;
            }

            if ($length > 4) {
                $longWordPoints += $points;
            }
        }

        ksort($byLength);

        $totalValidWords = $participant->stats['total_valid_words'] ?? null;

        return [
            'wordsFound' => count($words),
            'longestWord' => $longestWord,
            'longestWordLength' => $longestWord !== null ? mb_strlen($longestWord) : 0,
            'byLength' => $byLength,
            'totalValidWords' => $totalValidWords,
            'percentFound' => $totalValidWords ? round(count($words) / $totalValidWords * 100, 1) : null,
            'longWordPoints' => $longWordPoints,
        ];
    }

    /**
     * The best game for each headline metric, paired with the participant
     * it came from so the profile can link straight to it. Metrics with no
     * eligible game (e.g. no participant has a recorded board size yet) are
     * simply omitted rather than shown as a misleading zero.
     *
     * $mode restricts the eligible games to one `GameMode` -- deliberately
     * required by the profile page (via `bestMetricsByMode()`) rather than
     * left to blend every mode together, since "Highest score" mixes two
     * different scales otherwise: a room game's score is rewritten into an
     * "exclusive" score by `SharedSessionFinalizer` (a word found by more
     * than one player scores nothing for anyone), the same mixed-scale
     * problem `PlayerStatsService::avgScoreByMode()`'s own docblock warns
     * against. Every other metric here is derived from `found_words`,
     * which is untouched by that rewrite and so isn't actually
     * mode-sensitive -- but splitting the whole tile row by mode (rather
     * than just "Highest score") keeps one metric from silently mixing
     * games the rest of the row visibly separates.
     *
     * @return list<array{label: string, value: string, participant: GameParticipant}>
     */
    public function bestMetrics(User $user, ?GameMode $mode = null): array
    {
        $games = $this->gamesForUser($user);

        if ($mode !== null) {
            $games = $games->filter(fn (GameParticipant $participant) => $participant->gameSession->mode === $mode);
        }

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

        $mostWords = $decorated->sortByDesc(fn (array $entry) => $entry['stats']['wordsFound'])->first();
        $metrics[] = [
            'label' => 'Most words found',
            'value' => (string) $mostWords['stats']['wordsFound'],
            'participant' => $mostWords['participant'],
        ];

        $mostLongWordPoints = $decorated->sortByDesc(fn (array $entry) => $entry['stats']['longWordPoints'])->first();
        $metrics[] = [
            'label' => 'Most points from long words (5+ letters)',
            'value' => (string) $mostLongWordPoints['stats']['longWordPoints'],
            'participant' => $mostLongWordPoints['participant'],
        ];

        $withLongest = $decorated->filter(fn (array $entry) => $entry['stats']['longestWord'] !== null);

        if ($withLongest->isNotEmpty()) {
            $longest = $withLongest->sortByDesc(fn (array $entry) => $entry['stats']['longestWordLength'])->first();
            $metrics[] = [
                'label' => 'Longest word',
                'value' => strtoupper((string) $longest['stats']['longestWord']),
                'participant' => $longest['participant'],
            ];
        }

        $withPercent = $decorated->filter(fn (array $entry) => $entry['stats']['percentFound'] !== null);

        if ($withPercent->isNotEmpty()) {
            $bestPercent = $withPercent->sortByDesc(fn (array $entry) => $entry['stats']['percentFound'])->first();
            $metrics[] = [
                'label' => 'Most of the board found',
                'value' => $bestPercent['stats']['percentFound'].'%',
                'participant' => $bestPercent['participant'],
            ];
        }

        return $metrics;
    }

    /**
     * bestMetrics(), pre-split into the two modes Boggle actually uses
     * (Local/Async never occur for Boggle -- see `pages::boggle.play`,
     * which only ever writes `GameMode::Solo`; room play is the only
     * other mode, `GameMode::Online`) -- the shape the profile page
     * renders directly, so it doesn't need to know `GameMode` exists.
     *
     * @return array{solo: list<array{label: string, value: string, participant: GameParticipant}>, online: list<array{label: string, value: string, participant: GameParticipant}>}
     */
    public function bestMetricsByMode(User $user): array
    {
        return [
            'solo' => $this->bestMetrics($user, GameMode::Solo),
            'online' => $this->bestMetrics($user, GameMode::Online),
        ];
    }

    /**
     * The average, across every game with a recorded `total_valid_words`,
     * of that game's own percentFound -- an average of per-game
     * percentages, not (total words found / total valid words) across
     * every game, so a handful of small/short games don't get swamped by
     * one game with a much larger board. Mirrors bestMetrics()'s own
     * filtering, which skips participants recorded before this metric
     * existed (no `total_valid_words` stored at all). Null, not 0, when
     * no eligible game exists yet, so the caller can omit the tile
     * instead of showing a misleading 0%.
     */
    public function averagePercentFound(User $user): ?float
    {
        $percentages = $this->gamesForUser($user)
            ->map(fn (GameParticipant $participant) => $this->statsFor($participant)['percentFound'])
            ->filter(fn (?float $percentFound) => $percentFound !== null);

        if ($percentages->isEmpty()) {
            return null;
        }

        return round($percentages->avg(), 1);
    }

    /**
     * The longest distinct words the user has ever found across every
     * Boggle game, each paired with the most recent game it was found in.
     *
     * @return list<array{word: string, length: int, participant: GameParticipant}>
     */
    public function topLongestWords(User $user, int $limit = 10): array
    {
        // gamesForUser() is newest-first, so the first participant seen for
        // a given word here is the most recent game it was found in.
        $foundIn = [];

        foreach ($this->gamesForUser($user) as $participant) {
            foreach (array_keys($participant->stats['found_words'] ?? []) as $word) {
                $foundIn[$word] ??= $participant;
            }
        }

        $words = array_keys($foundIn);
        usort($words, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a) ?: $a <=> $b);

        return collect(array_slice($words, 0, $limit))
            ->map(fn (string $word) => [
                'word' => $word,
                'length' => mb_strlen($word),
                'participant' => $foundIn[$word],
            ])
            ->all();
    }

    /**
     * The words the user has found most often across every Boggle game,
     * each paired with the most recent game it was found in.
     *
     * @return list<array{word: string, count: int<0, max>, participant: GameParticipant}>
     */
    public function topFrequentWords(User $user, int $limit = 10): array
    {
        $counts = [];
        $foundIn = [];

        foreach ($this->gamesForUser($user) as $participant) {
            foreach (array_keys($participant->stats['found_words'] ?? []) as $word) {
                $counts[$word] = ($counts[$word] ?? 0) + 1;
                $foundIn[$word] ??= $participant;
            }
        }

        arsort($counts);

        return collect(array_slice($counts, 0, $limit, preserve_keys: true))
            ->map(fn (int $count, string $word) => [
                'word' => $word,
                'count' => $count,
                'participant' => $foundIn[$word],
            ])
            ->values()
            ->all();
    }
}
