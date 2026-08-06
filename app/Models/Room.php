<?php

namespace App\Models;

use App\Enums\RoomStatus;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
