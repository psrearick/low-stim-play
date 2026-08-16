<?php

namespace App\Analytics;

use App\Analytics\Models\AnalyticsDailyDimension;
use App\Analytics\Models\AnalyticsDailyTotal;
use App\Analytics\Models\AnalyticsEvent;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Reads App\Analytics\Models\AnalyticsDailyTotal/AnalyticsDailyDimension
 * for any date except today, and raw App\Analytics\Models\AnalyticsEvent
 * rows for today itself -- `analytics:rollup` only runs hourly (see
 * bootstrap/app.php), so today's rollup row, when one exists at all, is
 * always somewhat stale. Reading today live instead of summing it with
 * a stale rollup keeps the dashboard current between rollups without
 * ever double-counting.
 */
class AnalyticsRepository
{
    private const int TTL_SECONDS = 300;

    private const int LIVE_TTL_SECONDS = 30;

    public function totals(CarbonPeriod $period): Totals
    {
        // Cache a plain array, not a Totals instance -- unlike the
        // Collection/stdClass values every other cached method here
        // returns (a framework class always loaded on every request, and
        // a built-in PHP class needing no autoloading at all), Totals is
        // an app-defined class only autoloaded the first time something
        // actually calls this method. A worker/process whose autoloader
        // hasn't hit that code path yet can serialize a Totals instance
        // fine within its own request but can't reconstruct one back out
        // of Redis, producing __PHP_Incomplete_Class on unserialize. An
        // array has no such dependency.
        $data = Cache::remember($this->cacheKey('totals', $period), self::TTL_SECONDS, function () use ($period) {
            $rows = $this->dailyTotals($period);

            $pageviews = (int) $rows->sum('pageviews');
            $visitors = (int) $rows->sum('visitors');

            return [
                'pageviews' => $pageviews,
                'visitors' => $visitors,
                'viewsPerVisitor' => $visitors > 0 ? round($pageviews / $visitors, 2) : 0.0,
            ];
        });

        return new Totals(
            pageviews: $data['pageviews'],
            visitors: $data['visitors'],
            viewsPerVisitor: $data['viewsPerVisitor'],
        );
    }

    /**
     * Daily buckets, or hourly buckets when the period is a single day.
     *
     * @return Collection<int|string, array{pageviews: int, visitors: int}>
     */
    public function timeseries(CarbonPeriod $period): Collection
    {
        // Cache the plain array (->all()), not the Collection itself --
        // see totals()'s own comment above. Illuminate\Support\Collection
        // is a framework class always loaded well before any app code
        // runs, so this isn't the same "not yet autoloaded" story as
        // Totals was; caching only plain arrays/scalars throughout this
        // class removes object (de)serialization from the cache layer
        // entirely, which is the safer default regardless of what's
        // actually behind a given production Redis's unserialize failing
        // on an object it should have no trouble with.
        $data = Cache::remember($this->cacheKey('timeseries', $period), self::TTL_SECONDS, function () use ($period) {
            if ($this->isSingleDay($period)) {
                return $this->hourlyTimeseries($this->periodStart($period)->toDateString())->all();
            }

            return $this->dailyTotals($period)
                ->mapWithKeys(fn (array $row) => [$row['date'] => ['pageviews' => $row['pageviews'], 'visitors' => $row['visitors']]])
                ->all();
        });

        return collect($data);
    }

    /**
     * @return Collection<int, array{value: string, pageviews: int, visitors: int}>
     */
    public function topPages(CarbonPeriod $period, int $limit = 20): Collection
    {
        return $this->dimension($period, 'path', 'path', $limit);
    }

    /**
     * @return Collection<int, array{value: string, pageviews: int, visitors: int}>
     */
    public function countries(CarbonPeriod $period, int $limit = 30): Collection
    {
        return $this->dimension($period, 'country', 'country', $limit);
    }

    /**
     * @return Collection<int, array{value: string, pageviews: int, visitors: int}>
     */
    public function referrers(CarbonPeriod $period, int $limit = 20): Collection
    {
        return $this->dimension($period, 'referrer', 'referrer_host', $limit);
    }

    /**
     * @return Collection<int, array{value: string, pageviews: int, visitors: int}>
     */
    public function utmSources(CarbonPeriod $period, int $limit = 20): Collection
    {
        return $this->dimension($period, 'utm_source', 'utm_source', $limit);
    }

    public function liveVisitors(): int
    {
        return Cache::remember('analytics:live-visitors', self::LIVE_TTL_SECONDS, fn () => AnalyticsEvent::where('created_at', '>=', now()->subMinutes(30))
            ->distinct()
            ->count('visitor_hash'));
    }

    /**
     * @return Collection<int, array{date: string, pageviews: int, visitors: int}>
     */
    private function dailyTotals(CarbonPeriod $period): Collection
    {
        $dates = $this->datesInPeriod($period);
        $today = now()->toDateString();

        $rollups = AnalyticsDailyTotal::whereIn('date', $dates->reject(fn (string $date) => $date === $today))
            ->get()
            ->keyBy('date');

        return $dates->map(function (string $date) use ($rollups, $today) {
            if ($date === $today) {
                // ->toBase() -- these are aggregate rows (one per whole
                // day/dimension value), not real AnalyticsEvent instances,
                // so a plain query-builder stdClass row is the honest type
                // here rather than an Eloquent model with undeclared
                // pageviews/visitors/value attributes.
                $live = AnalyticsEvent::where('date', $date)
                    ->selectRaw('count(*) as pageviews, count(distinct visitor_hash) as visitors')
                    ->toBase()
                    ->first();

                // A plain aggregate with no GROUP BY always returns exactly
                // one row, even over zero matching source rows -- $live is
                // never actually null here.
                return ['date' => $date, 'pageviews' => (int) $live->pageviews, 'visitors' => (int) $live->visitors];
            }

            $row = $rollups->get($date);

            return ['date' => $date, 'pageviews' => (int) ($row->pageviews ?? 0), 'visitors' => (int) ($row->visitors ?? 0)];
        });
    }

    /**
     * @return Collection<int, array{pageviews: int, visitors: int}>
     */
    private function hourlyTimeseries(string $date): Collection
    {
        $rows = AnalyticsEvent::where('date', $date)
            ->selectRaw('hour, count(*) as pageviews, count(distinct visitor_hash) as visitors')
            ->groupBy('hour')
            ->toBase()
            ->get()
            ->keyBy('hour');

        return collect(range(0, 23))->mapWithKeys(function (int $hour) use ($rows) {
            $row = $rows->get($hour);

            return [$hour => ['pageviews' => (int) ($row->pageviews ?? 0), 'visitors' => (int) ($row->visitors ?? 0)]];
        });
    }

    /**
     * Combines the rollup table (every date but today) with a live query
     * against today's raw events (if the period includes it), grouping
     * by value in case the same value appears in both sources. Summing
     * per-day distinct-visitor counts across the merged rows is the same
     * "sum of daily uniques" approximation docs/specs/analytics.md
     * section 8.3 already mandates for cross-day visitor totals -- not a
     * new source of imprecision, just that same one applied per value.
     *
     * @return Collection<int, array{value: string, pageviews: int, visitors: int}>
     */
    private function dimension(CarbonPeriod $period, string $dimensionName, string $eventColumn, int $limit): Collection
    {
        $cacheKey = $this->cacheKey("dimension:{$dimensionName}:{$limit}", $period);

        // Same reasoning as timeseries() above -- cache the plain array,
        // not the Collection.
        $data = Cache::remember($cacheKey, self::TTL_SECONDS, function () use ($period, $dimensionName, $eventColumn, $limit) {
            $dates = $this->datesInPeriod($period);
            $today = now()->toDateString();
            $rollupDates = $dates->reject(fn (string $date) => $date === $today)->values();

            $rows = collect();

            if ($rollupDates->isNotEmpty()) {
                $rows = $rows->merge(
                    AnalyticsDailyDimension::whereIn('date', $rollupDates)
                        ->where('dimension', $dimensionName)
                        ->get(['value', 'pageviews', 'visitors'])
                );
            }

            if ($dates->contains($today)) {
                $rows = $rows->merge(
                    AnalyticsEvent::where('date', $today)
                        ->whereNotNull($eventColumn)
                        ->selectRaw("{$eventColumn} as value, count(*) as pageviews, count(distinct visitor_hash) as visitors")
                        ->groupBy($eventColumn)
                        ->toBase()
                        ->get()
                );
            }

            return $rows
                ->groupBy('value')
                ->map(fn (Collection $group, string $value) => [
                    'value' => $value,
                    'pageviews' => (int) $group->sum('pageviews'),
                    'visitors' => (int) $group->sum('visitors'),
                ])
                ->sortByDesc('pageviews')
                ->take($limit)
                ->values()
                ->all();
        });

        return collect($data);
    }

    /**
     * @return Collection<int, string>
     */
    private function datesInPeriod(CarbonPeriod $period): Collection
    {
        return collect($period)->map(fn (CarbonInterface $date) => $date->toDateString());
    }

    private function isSingleDay(CarbonPeriod $period): bool
    {
        return $this->periodStart($period)->isSameDay($this->periodEnd($period));
    }

    private function periodStart(CarbonPeriod $period): Carbon
    {
        return Carbon::instance($period->getStartDate());
    }

    private function periodEnd(CarbonPeriod $period): Carbon
    {
        return Carbon::instance($period->getEndDate() ?? $period->getStartDate());
    }

    private function cacheKey(string $method, CarbonPeriod $period): string
    {
        return sprintf(
            'analytics:%s:%s:%s',
            $method,
            $this->periodStart($period)->toDateString(),
            $this->periodEnd($period)->toDateString(),
        );
    }
}
