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
                [
                    'answer' => $question['answer'],
                    'alternate_answers' => $question['alternate_answers'] ?? [],
                ],
            );
        }

        // The JSON file is the single source of truth for this table's
        // contents -- prune any row whose text no longer appears in it,
        // so re-seeding after editing the bank converges to exactly the
        // current bank in every environment, not just wherever a
        // one-off cleanup was run by hand (docs/DEVLOG.md's Hoax
        // section: this happened for real, right after the original
        // 84-question bank was replaced with a fully audited 83-question
        // one). Safe to delete outright -- GameSession.state copies each
        // round's questionId/questionText as plain values at play time,
        // so no live foreign key or game history depends on a row still
        // existing after the fact.
        HoaxQuestion::whereNotIn('text', collect($questions)->pluck('text'))->delete();
    }
}
