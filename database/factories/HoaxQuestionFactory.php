<?php

namespace Database\Factories;

use App\Models\HoaxQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HoaxQuestion>
 */
class HoaxQuestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'text' => fake()->unique()->sentence().' ____.',
            'answer' => fake()->word(),
        ];
    }
}
