<?php

namespace Database\Factories;

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'host_user_id' => User::factory(),
            'status' => RoomStatus::Lobby,
            'max_players' => 8,
        ];
    }

    /**
     * Indicate that the room is currently active (game in progress).
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RoomStatus::Active,
        ]);
    }

    /**
     * Indicate that the room has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
