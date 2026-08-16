<?php

namespace App\Analytics\Console\Commands;

use App\Analytics\Models\AnalyticsDailyDimension;
use App\Analytics\Models\AnalyticsDailyTotal;
use App\Analytics\Models\AnalyticsEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent: delete-then-insert inside a transaction, per date, so
 * re-running for a date that's already rolled up (late-arriving rows,
 * a retry) never duplicates anything. Scheduled hourly with --days=2 so
 * today and yesterday both stay current -- see bootstrap/app.php.
 */
#[Signature('analytics:rollup {--date= : Specific date (Y-m-d) to roll up, instead of --days} {--days=2 : Number of trailing days ending today to roll up}')]
#[Description('Recompute analytics_daily_totals and analytics_daily_dimensions from analytics_events')]
class AnalyticsRollup extends Command
{
    /**
     * Maps each analytics_events column to the dimension name it's
     * stored under -- see docs/specs/analytics.md section 5.3.
     *
     * @var array<string, string>
     */
    private const array DIMENSIONS = [
        'path' => 'path',
        'country' => 'country',
        'referrer_host' => 'referrer',
        'utm_source' => 'utm_source',
    ];

    /**
     * Breakdowns past this many distinct values in a single day (referrer
     * spam is the usual culprit) get capped: the top rows by pageviews
     * are kept individually, the remainder folded into one '(other)' row.
     */
    private const int MAX_DIMENSION_ROWS = 500;

    public function handle(): int
    {
        foreach ($this->datesToRollup() as $date) {
            $this->rollupDate($date);
            $this->info("Rolled up {$date}.");
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function datesToRollup(): array
    {
        $date = $this->option('date');

        if (is_string($date) && $date !== '') {
            return [$date];
        }

        $days = max(1, (int) $this->option('days'));

        return collect(range(0, $days - 1))
            ->map(fn (int $offset) => now()->subDays($offset)->toDateString())
            ->all();
    }

    private function rollupDate(string $date): void
    {
        DB::transaction(function () use ($date) {
            AnalyticsDailyTotal::where('date', $date)->delete();
            AnalyticsDailyDimension::where('date', $date)->delete();

            // ->toBase() throughout this class -- these are aggregate rows
            // (one per whole day/dimension value), not real AnalyticsEvent
            // instances, so a plain query-builder stdClass row is the
            // honest type here rather than an Eloquent model with
            // undeclared pageviews/visitors/value attributes.
            $totals = AnalyticsEvent::where('date', $date)
                ->selectRaw('count(*) as pageviews, count(distinct visitor_hash) as visitors')
                ->toBase()
                ->first();

            // A plain aggregate with no GROUP BY always returns exactly one
            // row, even over zero matching source rows -- $totals is never
            // actually null here.
            AnalyticsDailyTotal::create([
                'date' => $date,
                'pageviews' => (int) $totals->pageviews,
                'visitors' => (int) $totals->visitors,
            ]);

            foreach (self::DIMENSIONS as $column => $dimensionName) {
                $this->rollupDimension($date, $column, $dimensionName);
            }
        });
    }

    private function rollupDimension(string $date, string $column, string $dimensionName): void
    {
        $rows = AnalyticsEvent::where('date', $date)
            ->whereNotNull($column)
            ->selectRaw("{$column} as value, count(*) as pageviews, count(distinct visitor_hash) as visitors")
            ->groupBy($column)
            ->orderByDesc('pageviews')
            ->toBase()
            ->get();

        foreach ($rows->take(self::MAX_DIMENSION_ROWS) as $row) {
            AnalyticsDailyDimension::create([
                'date' => $date,
                'dimension' => $dimensionName,
                'value' => $row->value,
                'pageviews' => $row->pageviews,
                'visitors' => $row->visitors,
            ]);
        }

        $overflow = $rows->slice(self::MAX_DIMENSION_ROWS);

        if ($overflow->isEmpty()) {
            return;
        }

        $otherTotals = AnalyticsEvent::where('date', $date)
            ->whereIn($column, $overflow->pluck('value')->all())
            ->selectRaw('count(*) as pageviews, count(distinct visitor_hash) as visitors')
            ->toBase()
            ->first();

        // Same reasoning as $totals above: never actually null.
        AnalyticsDailyDimension::create([
            'date' => $date,
            'dimension' => $dimensionName,
            'value' => '(other)',
            'pageviews' => (int) $otherTotals->pageviews,
            'visitors' => (int) $otherTotals->visitors,
        ]);
    }
}
