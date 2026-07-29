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
        Schema::create('family_feud_questions', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->string('prompt');
            // [{"text": "Towel", "points": 32}, ...], sorted descending by
            // points. Never sent to the client directly -- see
            // App\Services\FamilyFeud\JudgeService and the Livewire
            // component's own notes on why this column is only ever read
            // fresh from the database inside an action, never held as a
            // public (and therefore browser-visible) component property.
            $table->json('answers');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('family_feud_questions');
    }
};
