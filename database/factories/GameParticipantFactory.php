<?php

namespace Database\Factories;

use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameParticipant>
 */
class GameParticipantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_session_id' => GameSession::factory(),
            'user_id' => User::factory(),
            'guest_name' => null,
            'score' => fake()->numberBetween(0, 500),
            'result' => null,
            'stats' => [],
        ];
    }

    /**
     * Indicate that the participant is a guest player, not a registered user.
     */
    public function guest(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
            'guest_name' => fake()->firstName(),
        ]);
    }
}
