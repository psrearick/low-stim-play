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
        Schema::table('analytics_events', function (Blueprint $table) {
            // ISO 3166-2 composite, e.g. "US-CA" -- country + subdivision
            // code, not the subdivision code alone (a bare "CA" is
            // ambiguous: California vs a province elsewhere). Nullable --
            // MaxMind's GeoLite2-City has no subdivision data for every
            // IP (whole countries with no subdivisions in the free
            // dataset, or an IP the database can't resolve at all).
            $table->string('region', 8)->nullable()->after('country');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('analytics_events', function (Blueprint $table) {
            $table->dropColumn('region');
        });
    }
};
