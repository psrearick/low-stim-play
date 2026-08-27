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
        // Nullable, defaults to an empty list -- AnswerMatcher::matches()
        // treats a missing/empty value exactly like "no alternates," so
        // every pre-existing row stays correct without a backfill.
        Schema::table('hoax_questions', function (Blueprint $table) {
            $table->json('alternate_answers')->nullable()->after('answer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hoax_questions', function (Blueprint $table) {
            $table->dropColumn('alternate_answers');
        });
    }
};
