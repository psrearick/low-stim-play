<?php

namespace App\Models;

use App\Enums\GameType;
use Database\Factories\GameChallengeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A challenge sent from one user to another, notified by email
 * (GameChallengeReceived) and persisted here so it can also be
 * surfaced in-app -- the sidebar's Friends badge and the Friends
 * page's "Your challenges" section (see docs/TODO.md's former Task
 * 22). Row existence is the state: a challenge counts as pending for
 * both of those until GameChallengeService::dismiss() deletes it,
 * the same pending-or-deleted lifecycle Friendship already uses for
 * friend requests.
 */
#[Fillable(['from_user_id', 'to_user_id', 'game_type', 'link'])]
class GameChallenge extends Model
{
    /** @use HasFactory<GameChallengeFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function from(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function to(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'game_type' => GameType::class,
        ];
    }
}
