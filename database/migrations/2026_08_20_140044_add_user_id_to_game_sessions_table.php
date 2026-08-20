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
        // Nullable, and left unused by every existing game type -- room
        // games already identify their players via `room_code`/`RoomSeat`,
        // and every other solo game (Boggle) only ever writes a
        // GameSession row once the game is already over, so there's never
        // an "in-progress, needs to be found again later" row to look up.
        // This exists specifically for Word Blocks' resumable solo game
        // (docs/specs/quartiles.md): a nullable FK is enough to query "this
        // signed-in user's own in-progress session," set only while a
        // session is being used that way.
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
