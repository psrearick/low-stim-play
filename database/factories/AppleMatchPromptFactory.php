<?php

namespace Database\Factories;

use App\Models\AppleMatchDeck;
use App\Models\AppleMatchPrompt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppleMatchPrompt>
 */
class AppleMatchPromptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deck_id' => AppleMatchDeck::factory(),
            'text' => fake()->unique()->word(),
        ];
    }
}
