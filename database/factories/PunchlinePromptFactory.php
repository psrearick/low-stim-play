<?php

namespace Database\Factories;

use App\Models\PunchlinePrompt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PunchlinePrompt>
 */
class PunchlinePromptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'text' => fake()->unique()->sentence(),
        ];
    }
}
