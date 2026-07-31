<?php

namespace App\Services\Boggle;

use App\Enums\GameResult;
use App\Models\GameSession;

class SharedSessionFinalizer
{
    /**
     * Rescoes and re-ranks every participant recorded so far for an online
     * game session. A word worth points only if *no one else* in the
     * session also found it -- shared discoveries score nothing for
     * anyone, matching the room's "credit for what you alone got" rule.
     * Re-run every time a participant finishes, so it's self-correcting:
     * an early finisher's score/rank can still shift as later players'
     * found-word lists change what counts as "shared", converging once
     * everyone has finished.
     */
    public function finalize(GameSession $session): void
    {
        $participants = $session->participants()->get();

        /** @var array<string, int> $finderCounts */
        $finderCounts = [];

        foreach ($participants as $participant) {
            foreach (array_keys($participant->stats['found_words'] ?? []) as $word) {
                $finderCounts[$word] = ($finderCounts[$word] ?? 0) + 1;
            }
        }

        foreach ($participants as $participant) {
            $exclusiveScore = 0;

            foreach ($participant->stats['found_words'] ?? [] as $word => $points) {
                if ($finderCounts[$word] === 1) {
                    $exclusiveScore += $points;
                }
            }

            $participant->update(['score' => $exclusiveScore]);
        }

        $topScore = $participants->max('score');
        $winners = $participants->where('score', $topScore)->count();

        foreach ($participants as $participant) {
            $result = match (true) {
                $participant->score !== $topScore => GameResult::Loss,
                $winners > 1 => GameResult::Draw,
                default => GameResult::Win,
            };

            $participant->update(['result' => $result]);
        }
    }
}
