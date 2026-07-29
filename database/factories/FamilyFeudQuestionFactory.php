<?php

namespace Database\Factories;

use App\Models\FamilyFeudQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FamilyFeudQuestion>
 */
class FamilyFeudQuestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_id' => fake()->unique()->slug(3),
            'prompt' => 'Name something you would '.fake()->words(3, true).'.',
            'answers' => [
                ['text' => 'First', 'points' => 40],
                ['text' => 'Second', 'points' => 30],
                ['text' => 'Third', 'points' => 20],
                ['text' => 'Fourth', 'points' => 10],
            ],
        ];
    }
}
