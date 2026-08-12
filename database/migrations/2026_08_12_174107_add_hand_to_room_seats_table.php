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
            // Nullable -- every other game's own RoomSeat::create() sets
            // nothing here, same reasoning as team_index/is_captain before
            // it. Holds the list of apple_match_cards ids this seat
            // currently holds -- the one piece of per-seat state that's
            // safe to persist as an ordinary column (unlike round
            // attribution, which never touches a broadcast state blob;
            // see docs/specs/apple-match.md section 3).
            $table->json('hand')->nullable()->after('is_captain');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('room_seats', function (Blueprint $table) {
            $table->dropColumn('hand');
        });
    }
};
