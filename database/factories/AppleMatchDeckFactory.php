<?php

namespace Database\Factories;

use App\Models\AppleMatchDeck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppleMatchDeck>
 */
class AppleMatchDeckFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'description' => null,
            'owner_user_id' => null,
            'source' => 'system',
            'generation_prompt' => null,
        ];
    }
}
