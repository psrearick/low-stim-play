<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'username', 'is_public', 'is_guest', 'avatar_path'])]
#[Hidden(['password', 'remember_token', 'two_factor_recovery_codes', 'two_factor_secret'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Mirrors the `users.is_public`/`is_guest` column defaults so a freshly
     * created, unrefreshed instance already has valid (non-null) boolean
     * values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_public' => true,
        'is_guest' => false,
    ];

    /**
     * @return HasMany<GameParticipant, $this>
     */
    public function gameParticipants(): HasMany
    {
        return $this->hasMany(GameParticipant::class);
    }

    /**
     * Null when no image has been uploaded -- `<x-avatar>` falls back to
     * the generated initial-letter badge in that case.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('s3')->url($this->avatar_path) : null;
    }

    /**
     * Creates (but does not log in) a throwaway account for someone joining
     * or hosting an online room without registering. A real `users` row is
     * the only way to satisfy Laravel's presence-channel authorization,
     * which always authenticates via the guard and has no "anonymous"
     * option. `is_guest` marks it as one of these rather than a real
     * account -- see `App\Console\Commands\PruneGuestAccounts` for how
     * these eventually get cleaned up.
     */
    public static function createGuest(?string $name = null): self
    {
        $name = trim((string) $name);

        return self::create([
            'name' => $name !== '' ? $name : 'Guest',
            'email' => 'guest-'.Str::uuid().'@guests.invalid',
            'password' => Hash::make(Str::random(40)),
            'is_guest' => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_public' => 'boolean',
            'is_guest' => 'boolean',
        ];
    }
}
