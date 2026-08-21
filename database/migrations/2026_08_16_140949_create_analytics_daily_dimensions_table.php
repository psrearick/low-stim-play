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
        Schema::create('analytics_daily_dimensions', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('dimension', 24); // 'path' | 'country' | 'region' | 'referrer' | 'utm_source'
            $table->string('value', 255);
            $table->unsignedInteger('pageviews');
            $table->unsignedInteger('visitors');
            $table->timestamps();

            $table->unique(['date', 'dimension', 'value']);
            $table->index(['dimension', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_daily_dimensions');
    }
};
