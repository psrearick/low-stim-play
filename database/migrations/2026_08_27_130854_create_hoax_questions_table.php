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
        // Flat question bank, no deck/set concept -- same "no deck/set
        // concept" default as punchline_prompts (docs/specs/hoax.md
        // section 2). `text` contains a literal "____" placeholder;
        // `answer` is the true fill for it.
        Schema::create('hoax_questions', function (Blueprint $table) {
            $table->id();
            $table->string('text');
            $table->string('answer');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hoax_questions');
    }
};
