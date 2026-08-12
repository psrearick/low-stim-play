<?php

namespace Database\Factories;

use App\Models\AppleMatchCard;
use App\Models\AppleMatchDeck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppleMatchCard>
 */
class AppleMatchCardFactory extends Factory
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
            'text' => fake()->unique()->words(2, true),
        ];
    }
}
