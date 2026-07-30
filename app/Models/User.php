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
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;

#[Fillable(['name', 'email', 'password', 'username', 'is_public', 'is_guest'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable;

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
     * Creates (but does not log in) a throwaway account for someone joining
     * an online Boggle room without registering. A real `users` row is the
     * only way to satisfy Laravel's presence-channel authorization, which
     * always authenticates via the guard and has no "anonymous" option --
     * `is_guest` is what stops one of these from being able to host a room
     * of their own.
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
