<?php

namespace Database\Factories;

use App\Enums\GameType;
use App\Models\GameChallenge;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameChallenge>
 */
class GameChallengeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_user_id' => User::factory(),
            'to_user_id' => User::factory(),
            'game_type' => GameType::Boggle,
            'link' => $this->faker->url(),
        ];
    }
}
