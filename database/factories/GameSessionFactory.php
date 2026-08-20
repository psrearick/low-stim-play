<?php

namespace Database\Factories;

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameSession>
 */
class GameSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(GameType::cases()),
            'mode' => GameMode::Solo,
            'room_code' => null,
            'settings' => [],
            'started_at' => now(),
            'ended_at' => null,
        ];
    }

    /**
     * Indicate that the session is an online multiplayer room.
     */
    public function online(): static
    {
        return $this->state(fn (array $attributes) => [
            'mode' => GameMode::Online,
            'room_code' => strtoupper(fake()->bothify('??????')),
        ]);
    }

    /**
     * Indicate that the session has already ended.
     */
    public function ended(): static
    {
        return $this->state(fn (array $attributes) => [
            'ended_at' => now(),
        ]);
    }

    /**
     * Attaches a resumable-in-progress session to a specific user -- see
     * GameSession::user()'s own docblock for what this is (and isn't) for.
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }
}
