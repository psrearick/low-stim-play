<?php

namespace App\Models;

use App\Enums\GameMode;
use App\Enums\GameType;
use Database\Factories\GameSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property GameType $type
 * @property GameMode $mode
 * @property array<string, mixed> $settings
 * @property array<string, mixed>|null $state
 * @property string|null $daily_date A plain `Y-m-d` string, deliberately
 *                                   uncast -- Eloquent's `date` cast reformats to a full datetime on
 *                                   *write* (only truncating on read), which drifts from a bare
 *                                   `toDateString()` under sqlite's dynamic typing (no real DATE column).
 *                                   Left as a raw string so every write/compare uses the exact same
 *                                   `Y-m-d` value everywhere, DB driver notwithstanding.
 */
#[Fillable(['user_id', 'type', 'mode', 'room_code', 'is_daily', 'daily_date', 'settings', 'state', 'started_at', 'ended_at'])]
class GameSession extends Model
{
    /** @use HasFactory<GameSessionFactory> */
    use HasFactory;

    /**
     * @return HasMany<GameParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(GameParticipant::class);
    }

    /**
     * Only ever set for a solo, resumable-in-progress session (currently
     * just Word Blocks) -- null for every room game (identified by
     * `room_code`/`RoomSeat` instead) and for any solo game whose
     * GameSession is only ever written once it's already finished.
     *
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
            'type' => GameType::class,
            'mode' => GameMode::class,
            'is_daily' => 'boolean',
            'settings' => 'array',
            'state' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
