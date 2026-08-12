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
        Schema::create('apple_match_decks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            // Null = a system/default deck, not owned by any user -- the
            // only kind that exists today. See docs/specs/apple-match.md
            // section 3: cards are grouped under a deck from day one so a
            // future user-authored/AI-generated deck (docs/notes.md,
            // 2026-08-12) is a new row shape here, not a schema rework.
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source')->default('system');
            $table->text('generation_prompt')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apple_match_decks');
    }
};
