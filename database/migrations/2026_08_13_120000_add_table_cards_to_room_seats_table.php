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
            // Nullable -- only Clear the Deck's own onRoomStarting() sets
            // these, same reasoning as team_index/is_captain/hand before
            // them. Both are unordered bags of plain integer card values
            // (1-13; suits aren't used in this game -- see docs/specs/
            // clear-the-deck.md section 4), not per-slot pairs: the
            // physical "which face-up card covers which face-down card"
            // pairing never matters beyond a count, so it's never modeled.
            $table->json('table_face_up')->nullable()->after('hand');

            // Never broadcast and never assigned to any Livewire public
            // property -- read fresh from this column only inside
            // playCards() the one time it needs to resolve a flip. This is
            // the app's first "secret from its own owner" state; see
            // docs/specs/clear-the-deck.md section 1/4.
            $table->json('table_face_down')->nullable()->after('table_face_up');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('room_seats', function (Blueprint $table) {
            $table->dropColumn(['table_face_up', 'table_face_down']);
        });
    }
};
