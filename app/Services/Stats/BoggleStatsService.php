<?php

namespace App\Services\Stats;

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
     * @return array{wordsFound: int<0, max>, longestWord: ?string, longestWordLength: int<0, max>, byLength: array<int, int<0, max>>, totalValidWords: ?int, percentFound: ?float}
     */
    public function statsFor(GameParticipant $participant): array
    {
        $foundWords = $participant->stats['found_words'] ?? [];
        $words = array_keys($foundWords);

        $byLength = [];
        $longestWord = null;

        foreach ($words as $word) {
            $length = mb_strlen($word);
            $byLength[$length] = ($byLength[$length] ?? 0) + 1;

            if ($longestWord === null || $length > mb_strlen($longestWord)) {
                $longestWord = $word;
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
        ];
    }

    /**
     * The best game for each headline metric, paired with the participant
     * it came from so the profile can link straight to it. Metrics with no
     * eligible game (e.g. no participant has a recorded board size yet) are
     * simply omitted rather than shown as a misleading zero.
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

        $mostWords = $decorated->sortByDesc(fn (array $entry) => $entry['stats']['wordsFound'])->first();
        $metrics[] = [
            'label' => 'Most words found',
            'value' => (string) $mostWords['stats']['wordsFound'],
            'participant' => $mostWords['participant'],
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
