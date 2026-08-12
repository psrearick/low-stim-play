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
        // The green prompt cards. Content is never secret from anyone --
        // unlike App\Models\FamilyFeudQuestion's answers, only *who
        // currently holds which dealt card* (App\Models\RoomSeat::$hand)
        // is private.
        Schema::create('apple_match_prompts', function (Blueprint $table) {
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
        Schema::dropIfExists('apple_match_prompts');
    }
};
