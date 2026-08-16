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
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedTinyInteger('hour'); // 0-23, UTC
            $table->char('visitor_hash', 64);
            $table->string('path', 255); // normalized -- see App\Analytics\PathNormalizer
            $table->string('route_name', 128)->nullable();
            $table->char('country', 2)->nullable(); // ISO 3166-1 alpha-2
            $table->string('referrer_host', 128)->nullable();
            $table->string('utm_source', 64)->nullable();
            $table->string('utm_medium', 64)->nullable();
            $table->string('utm_campaign', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['date', 'path']);
            $table->index(['date', 'visitor_hash']);
            $table->index(['created_at']); // for the live-visitors query
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
