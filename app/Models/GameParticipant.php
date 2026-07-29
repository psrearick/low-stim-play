<?php

namespace App\Models;

use App\Enums\GameResult;
use Database\Factories\GameParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ?GameResult $result
 * @property ?array<string, mixed> $stats
 */
#[Fillable(['game_session_id', 'user_id', 'guest_name', 'score', 'result', 'stats'])]
class GameParticipant extends Model
{
    /** @use HasFactory<GameParticipantFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<GameSession, $this>
     */
    public function gameSession(): BelongsTo
    {
        return $this->belongsTo(GameSession::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => GameResult::class,
            'stats' => 'array',
        ];
    }
}
