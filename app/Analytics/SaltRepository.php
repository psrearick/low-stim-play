<?php

namespace App\Analytics;

use App\Analytics\Models\AnalyticsSalt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The daily secret behind App\Analytics\Http\Middleware\RecordPageview's
 * visitor hash -- see docs/specs/analytics.md's privacy invariants for
 * why it rotates daily and why it is never logged.
 */
class SaltRepository
{
    public function forToday(): string
    {
        return $this->forDate(now()->toDateString());
    }

    public function forDate(string $date): string
    {
        return Cache::remember(
            "analytics:salt:{$date}",
            now()->addHours(25),
            // firstOrCreate on the unique `date` column so two requests
            // racing at midnight can't each create a salt row for the
            // same day -- the second call just finds the first's row.
            fn () => AnalyticsSalt::firstOrCreate(
                ['date' => $date],
                ['salt' => Str::random(64)],
            )->salt
        );
    }
}
