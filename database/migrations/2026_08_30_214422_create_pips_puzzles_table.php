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
        Schema::create('pips_puzzles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seed');
            $table->string('difficulty');
            $table->json('board');
            $table->json('solution');
            $table->timestamps();

            // A bare seed isn't a unique key once difficulty is a second
            // axis -- the same seed int can legitimately be requested at
            // any of the three PipsDifficulty tiers, each producing a
            // genuinely different puzzle (docs/specs/pips.md section 4).
            $table->unique(['seed', 'difficulty']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pips_puzzles');
    }
};
