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
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->boolean('is_daily')->default(false)->after('room_code');
            $table->date('daily_date')->nullable()->after('is_daily');
            $table->index(['user_id', 'type', 'is_daily', 'daily_date'], 'game_sessions_daily_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->dropIndex('game_sessions_daily_lookup_index');
            $table->dropColumn(['is_daily', 'daily_date']);
        });
    }
};
