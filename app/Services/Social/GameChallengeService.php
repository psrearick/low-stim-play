<?php

namespace App\Services\Social;

use App\Enums\GameType;
use App\Models\GameChallenge;
use App\Models\GameParticipant;
use App\Models\User;
use App\Notifications\GameChallengeReceived;
use Illuminate\Support\Collection;
use LogicException;

class GameChallengeService
{
    /**
     * The link to a friend's own finished game, for a "Challenge back"
     * flow -- Boggle's is its existing shared/ranked session link (joining
     * $participant's own GameSession, same as the manual copy-link box on
     * its reveal screen); Word Blocks has no ranking, so its link just
     * reproduces the same seed independently. Five Letters follows
     * Boggle's shared-session model, not Word Blocks' seed link -- its
     * answer is secret, so there's no public seed to hand out at all (see
     * docs/specs/wordle.md section 3); the friend instead joins the same
     * GameSession and gets the identical secret word resolved server-side.
     * Word Hive has no hidden information either (docs/specs/
     * spelling-bee.md section 1), so it follows Word Blocks' plain-seed
     * shape, not Wordle's/Boggle's shared-session one.
     */
    public function linkFor(GameParticipant $participant): string
    {
        $session = $participant->gameSession;

        return match ($session->type) {
            GameType::Boggle => route('boggle.share', ['gameSession' => $session->id]),
            GameType::Quartiles => route('quartiles.play', ['seed' => $session->settings['seed']]),
            GameType::Wordle => route('wordle.share', ['gameSession' => $session->id]),
            GameType::SpellingBee => route('spelling-bee.play', ['seed' => $session->settings['seed']]),
            default => throw new LogicException("{$session->type->value} has no challenge link."),
        };
    }

    /**
     * No-op for a guest recipient -- same reasoning as
     * FriendService::sendRequest()'s own guard: a guest's email is an
     * unresolvable @guests.invalid placeholder (see User::createGuest()).
     *
     * Persists a GameChallenge row alongside the email so the challenge
     * can also surface in-app (sidebar badge, Friends page "Your
     * challenges" section) -- previously this only ever emailed, with
     * nothing recorded anywhere for the app itself to show.
     */
    public function send(User $from, User $to, string $link, GameType $gameType): void
    {
        if ($to->is_guest) {
            return;
        }

        GameChallenge::create([
            'from_user_id' => $from->id,
            'to_user_id' => $to->id,
            'game_type' => $gameType,
            'link' => $link,
        ]);

        $to->notify(new GameChallengeReceived($from, $gameType, $link));
    }

    /**
     * Challenges $user has received and not yet dismissed, newest first.
     *
     * @return Collection<int, GameChallenge>
     */
    public function receivedFor(User $user): Collection
    {
        return GameChallenge::query()
            ->where('to_user_id', $user->id)
            ->with('from')
            ->latest()
            ->get();
    }

    public function dismiss(GameChallenge $challenge): void
    {
        $challenge->delete();
    }
}
