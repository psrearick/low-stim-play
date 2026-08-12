<?php

namespace App\Models;

use App\Enums\GameType;
use App\Enums\RoomStatus;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

#[Fillable(['code', 'game_session_id', 'host_user_id', 'status', 'max_players', 'expires_at'])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    /**
     * The default lifetime of a room before it expires.
     */
    public const int LIFETIME_MINUTES = 60;

    /**
     * Room codes are read aloud and typed on phone keyboards, so visually
     * ambiguous characters (0/O, 1/I) are excluded from the alphabet.
     */
    private const string CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    protected static function booted(): void
    {
        static::creating(function (Room $room): void {
            $room->code ??= static::generateUniqueCode();
            $room->expires_at ??= now()->addMinutes(self::LIFETIME_MINUTES);
        });

        // The host is a member of their own room from the instant it
        // exists -- every other join path (joinRoom(), a game's own
        // room.blade.php mount(), pages::room.show's mount()) calls
        // addMember() explicitly, but the host has no separate "join"
        // step of their own to hang that call off of.
        static::created(function (Room $room): void {
            $room->addMember($room->host);
        });
    }

    /**
     * Generate a room code that isn't currently in use.
     */
    public static function generateUniqueCode(): string
    {
        do {
            $code = '';

            for ($i = 0; $i < 6; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (static::where('code', $code)->exists());

        return $code;
    }

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
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    /**
     * @return HasMany<RoomMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(RoomMember::class);
    }

    /**
     * Records that $user belongs to this room -- the one durable "I am in
     * this room" fact, independent of both ephemeral presence-channel
     * membership (who's connected to room.{code} *right now*) and
     * RoomSeat (who has a seat in the *currently active game*, recreated
     * every time one starts). Every existing join/host/direct-URL entry
     * point calls this instead of duplicating the membership logic.
     */
    public function addMember(User $user): RoomMember
    {
        return $this->members()->firstOrCreate(['user_id' => $user->id]);
    }

    /**
     * Records "the host is currently sitting on {$type}'s own lobby page,"
     * so a member who loads the room hub *after* that already happened
     * (missing the real-time RoomGameSwitched broadcast entirely -- that
     * only ever reaches whoever's actively viewing the hub at the instant
     * it fires) still has a way to find and join it. Deliberately Cache,
     * not a DB column -- Room stays decoupled from any specific game (see
     * docs/DEVLOG.md's Room system section), and this is exactly the kind
     * of ephemeral, non-authoritative state <livewire:game-lobby>'s own
     * ready-flag cache already uses the same mechanism for. Superseded
     * the instant a real GameSession exists (see pendingGameType() below)
     * and naturally overwritten if the host instead visits a *different*
     * game's own lobby -- a single key per room, not per game.
     */
    public function markPendingGame(GameType $type): void
    {
        Cache::put($this->pendingGameCacheKey(), $type->value, now()->addMinutes(self::LIFETIME_MINUTES));
    }

    /**
     * Null once a real GameSession exists -- pages::room.show's own
     * activeGameType always takes precedence once it's set, so a stale
     * pending marker left over from an abandoned lobby visit is simply
     * never consulted rather than needing to be explicitly cleared.
     */
    public function pendingGameType(): ?GameType
    {
        if ($this->game_session_id !== null) {
            return null;
        }

        $value = Cache::get($this->pendingGameCacheKey());

        return $value !== null ? GameType::from($value) : null;
    }

    private function pendingGameCacheKey(): string
    {
        return "room:{$this->code}:pending-game";
    }

    /**
     * @param  Builder<Room>  $query
     * @return Builder<Room>
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RoomStatus::class,
            'expires_at' => 'datetime',
        ];
    }
}
