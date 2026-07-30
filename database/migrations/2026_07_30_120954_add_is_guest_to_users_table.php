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
        Schema::table('users', function (Blueprint $table) {
            // Backs anonymous room participants (see Boggle's online rooms):
            // a real `users` row, auto-created and logged in on the spot, so
            // Laravel's presence-channel authorization (which always requires
            // an authenticated guard user, with no "no auth at all" option)
            // still works for someone who never registered. Distinguishes
            // these from real accounts so only a real one can host a room.
            $table->boolean('is_guest')->default(false)->after('is_public');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_guest');
        });
    }
};
