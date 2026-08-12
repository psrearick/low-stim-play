<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The red answer cards. See create_apple_match_prompts_table's own
        // note -- content is never secret, only current possession is.
        Schema::create('apple_match_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deck_id')->constrained('apple_match_decks')->cascadeOnDelete();
            $table->string('text');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apple_match_cards');
    }
};
