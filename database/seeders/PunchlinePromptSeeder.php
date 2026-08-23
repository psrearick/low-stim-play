<?php

namespace Database\Seeders;

use App\Models\PunchlinePrompt;
use Illuminate\Database\Seeder;

class PunchlinePromptSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $prompts = json_decode(
            file_get_contents(__DIR__.'/data/punchline_prompts.json'),
            associative: true,
        );

        foreach ($prompts as $prompt) {
            PunchlinePrompt::updateOrCreate(['text' => $prompt['text']]);
        }
    }
}
