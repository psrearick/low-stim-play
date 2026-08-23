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
        // Flat prompt bank, no deck/set concept -- unlike apple_match_prompts,
        // nothing here asked for user-authored/AI-generated prompt sets, see
        // docs/specs/punchline.md section 2.
        Schema::create('punchline_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('text');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('punchline_prompts');
    }
};
