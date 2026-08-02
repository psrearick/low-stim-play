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
        Schema::table('room_seats', function (Blueprint $table) {
            // Nullable/defaulted -- Yahtzee's own RoomSeat::create() sets
            // neither, since Yahtzee has no teams. Family Feud's online
            // room is the first (and so far only) consumer of these.
            $table->unsignedInteger('team_index')->nullable()->after('user_id');
            $table->boolean('is_captain')->default(false)->after('team_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('room_seats', function (Blueprint $table) {
            $table->dropColumn(['team_index', 'is_captain']);
        });
    }
};
