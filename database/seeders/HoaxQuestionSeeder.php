<?php

namespace Database\Seeders;

use App\Models\HoaxQuestion;
use Illuminate\Database\Seeder;

class HoaxQuestionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $questions = json_decode(
            file_get_contents(__DIR__.'/data/hoax_questions.json'),
            associative: true,
        );

        foreach ($questions as $question) {
            HoaxQuestion::updateOrCreate(
                ['text' => $question['text']],
                ['answer' => $question['answer']],
            );
        }
    }
}
