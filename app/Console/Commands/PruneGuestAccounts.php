<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('users:prune-guests')]
#[Description('Delete guest accounts (created by joining or hosting an online room without registering) older than a day')]
class PruneGuestAccounts extends Command
{
    /**
     * Guest accounts (`User::createGuest()`) exist purely to authenticate
     * into a room -- rooms themselves expire after `Room::LIFETIME_MINUTES`
     * (60), and anything actually worth keeping from a game a guest played
     * is already durably recorded via `GameParticipant`, independent of
     * whether the `User` row survives (`game_participants.user_id` is
     * nullable/`nullOnDelete`, the same mechanism the "Delete Account"
     * feature already relies on). A day is a generous buffer past the
     * room's own 1-hour expiry, not a value with any other significance.
     */
    private const int MAX_AGE_HOURS = 24;

    public function handle(): int
    {
        $count = User::where('is_guest', true)
            ->where('created_at', '<', now()->subHours(self::MAX_AGE_HOURS))
            ->delete();

        $this->info("Deleted {$count} guest account(s) older than ".self::MAX_AGE_HOURS.' hours.');

        return self::SUCCESS;
    }
}
