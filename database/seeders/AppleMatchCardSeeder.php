<?php

namespace Database\Seeders;

use App\Models\AppleMatchCard;
use App\Models\AppleMatchDeck;
use App\Models\AppleMatchPrompt;
use Illuminate\Database\Seeder;

class AppleMatchCardSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Exactly one system deck for v1 -- see docs/specs/apple-match.md
        // section 3. name/description are the only fields worth
        // re-syncing on a repeat run; owner_user_id/source stay whatever
        // they were created with.
        $deck = AppleMatchDeck::updateOrCreate(
            ['owner_user_id' => null, 'source' => 'system'],
            ['name' => 'Classic', 'description' => 'The default Best Match card set.'],
        );

        $prompts = json_decode(
            file_get_contents(__DIR__.'/data/apple_match_prompts.json'),
            associative: true,
        );

        foreach ($prompts as $prompt) {
            AppleMatchPrompt::updateOrCreate(
                ['deck_id' => $deck->id, 'text' => $prompt['text']],
            );
        }

        $cards = json_decode(
            file_get_contents(__DIR__.'/data/apple_match_cards.json'),
            associative: true,
        );

        foreach ($cards as $card) {
            AppleMatchCard::updateOrCreate(
                ['deck_id' => $deck->id, 'text' => $card['text']],
            );
        }
    }
}
