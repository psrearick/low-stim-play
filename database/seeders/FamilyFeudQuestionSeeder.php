<?php

namespace Database\Seeders;

use App\Models\FamilyFeudQuestion;
use Illuminate\Database\Seeder;

class FamilyFeudQuestionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $questions = json_decode(
            file_get_contents(__DIR__.'/data/family_feud_questions.json'),
            associative: true,
        );

        foreach ($questions as $question) {
            FamilyFeudQuestion::updateOrCreate(
                ['external_id' => $question['id']],
                ['prompt' => $question['prompt'], 'answers' => $question['answers']],
            );
        }
    }
}
