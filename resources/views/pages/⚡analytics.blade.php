<?php

use App\Analytics\AnalyticsRepository;
use App\Analytics\Geo\CountryNames;
use App\Analytics\Geo\RegionNames;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $range = '7d';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    /**
     * @var list<string>
     */
    public array $expandedPanels = [];

    public function mount(): void
    {
        abort_unless(Gate::allows(config('analytics.dashboard.gate')), 403);
    }

    public function setRange(string $range): void
    {
        $this->range = $range;

        if ($range !== 'custom') {
            $this->from = null;
            $this->to = null;
        }
    }

    public function togglePanel(string $key): void
    {
        $this->expandedPanels = in_array($key, $this->expandedPanels, true)
            ? array_values(array_diff($this->expandedPanels, [$key]))
            : [...$this->expandedPanels, $key];
    }

    public function delta(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * @return array{value: string, label: string}[]
     */
    public function rangeOptions(): array
    {
        return [
            ['value' => '7d', 'label' => '7 days'],
            ['value' => '30d', 'label' => '30 days'],
            ['value' => '90d', 'label' => '90 days'],
            ['value' => 'month', 'label' => 'This month'],
            ['value' => 'custom', 'label' => 'Custom'],
        ];
    }

    public function getPeriodProperty(): CarbonPeriod
    {
        [$start, $end] = $this->resolveRange();

        return CarbonPeriod::create($start, $end);
    }

    public function getComparisonPeriodProperty(): CarbonPeriod
    {
        $start = Carbon::instance($this->period->getStartDate());
        $end = Carbon::instance($this->period->getEndDate() ?? $this->period->getStartDate());
        $days = $start->diffInDays($end) + 1;

        return CarbonPeriod::create(
            $start->copy()->subDays($days),
            $start->copy()->subDay(),
        );
    }

    public function getTotalsProperty()
    {
        return app(AnalyticsRepository::class)->totals($this->period);
    }

    public function getPreviousTotalsProperty()
    {
        return app(AnalyticsRepository::class)->totals($this->comparisonPeriod);
    }

    public function getLiveVisitorsProperty(): int
    {
        return app(AnalyticsRepository::class)->liveVisitors();
    }

    /**
     * @return array{width: int, height: int, pageviewPoints: string, visitorPoints: string, labels: array<int, array{x: float, label: string}>}
     */
    public function getChartProperty(): array
    {
        $series = app(AnalyticsRepository::class)->timeseries($this->period);
        $keys = $series->keys()->values();
        $count = $keys->count();
        $isHourly = $count > 0 && is_int($keys->first());

        $width = 960;
        $height = 220;
        $stepX = $count > 1 ? $width / ($count - 1) : 0;
        $maxValue = max(1, (int) $series->max('pageviews'), (int) $series->max('visitors'));

        $points = fn (string $field) => $series->values()
            ->map(fn (array $row, int $i) => round($i * $stepX, 1).','.round($height - ($row[$field] / $maxValue) * $height, 1))
            ->implode(' ');

        $tickCount = min(6, max(1, $count));
        $tickIndexes = $count <= 1
            ? collect([0])
            : collect(range(0, $tickCount - 1))->map(fn (int $i) => (int) round($i * ($count - 1) / ($tickCount - 1)))->unique()->values();

        $labels = $tickIndexes->map(function (int $i) use ($keys, $isHourly, $stepX) {
            $key = $keys->get($i);
            $label = $isHourly ? sprintf('%02d:00', $key) : Carbon::parse($key)->format('M j');

            return ['x' => round($i * $stepX, 1), 'label' => $label];
        })->all();

        // Top-to-bottom (max down to 0) so it renders directly against a
        // flex column paired with justify-between -- matches the evenly
        // spaced gridlines drawn at the same fractions of $height.
        $yTicks = collect([4, 3, 2, 1, 0])->map(fn (int $step) => [
            'value' => number_format((int) round(($step / 4) * $maxValue)),
            'y' => round($height - ($step / 4) * $height, 1),
        ])->all();

        return [
            'width' => $width,
            'height' => $height,
            'pageviewPoints' => $points('pageviews'),
            'visitorPoints' => $points('visitors'),
            'labels' => $labels,
            'yTicks' => $yTicks,
        ];
    }

    /**
     * @return Collection<int, array{label: string, pageviews: int, visitors: int}>
     */
    public function getTopPagesProperty(): Collection
    {
        return app(AnalyticsRepository::class)->topPages($this->period)
            ->map(fn (array $row) => ['label' => $row['value'], 'pageviews' => $row['pageviews'], 'visitors' => $row['visitors']]);
    }

    /**
     * @return Collection<int, array{label: string, pageviews: int, visitors: int}>
     */
    public function getCountriesProperty(): Collection
    {
        return app(AnalyticsRepository::class)->countries($this->period)
            ->map(fn (array $row) => ['label' => CountryNames::name($row['value']), 'pageviews' => $row['pageviews'], 'visitors' => $row['visitors']]);
    }

    /**
     * @return Collection<int, array{label: string, pageviews: int, visitors: int}>
     */
    public function getRegionsProperty(): Collection
    {
        return app(AnalyticsRepository::class)->regions($this->period)
            ->map(fn (array $row) => ['label' => RegionNames::label($row['value']), 'pageviews' => $row['pageviews'], 'visitors' => $row['visitors']]);
    }

    /**
     * @return Collection<int, array{label: string, pageviews: int, visitors: int}>
     */
    public function getReferrersProperty(): Collection
    {
        return app(AnalyticsRepository::class)->referrers($this->period)
            ->map(fn (array $row) => ['label' => $row['value'], 'pageviews' => $row['pageviews'], 'visitors' => $row['visitors']]);
    }

    /**
     * @return Collection<int, array{label: string, pageviews: int, visitors: int}>
     */
    public function getUtmSourcesProperty(): Collection
    {
        return app(AnalyticsRepository::class)->utmSources($this->period)
            ->map(fn (array $row) => ['label' => $row['value'], 'pageviews' => $row['pageviews'], 'visitors' => $row['visitors']]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveRange(): array
    {
        $today = now()->toDateString();

        return match ($this->range) {
            '30d' => [now()->subDays(29)->toDateString(), $today],
            '90d' => [now()->subDays(89)->toDateString(), $today],
            'month' => [now()->startOfMonth()->toDateString(), $today],
            'custom' => [$this->from ?: now()->subDays(6)->toDateString(), $this->to ?: $today],
            default => [now()->subDays(6)->toDateString(), $today],
        };
    }
};
?>

<x-slot:title>Analytics — Low Stim Play</x-slot>

<div class="flex flex-col items-center gap-6 w-full max-w-5xl mx-auto" wire:poll.30s>
    <div class="flex flex-wrap items-center justify-between gap-4 w-full">
        <div class="flex items-center gap-3">
            <h1 class="text-bar font-bold tracking-wide text-2xl">Analytics</h1>
            <span class="text-xs text-text/65 bg-surface border-2 border-surface-border rounded-lg px-3 py-1" style="font-variant-numeric: tabular-nums;">
                {{ $this->liveVisitors }} {{ Str::plural('visitor', $this->liveVisitors) }} in the last 30 minutes
            </span>
        </div>

        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Date range">
            @foreach ($this->rangeOptions() as $option)
                <button
                    type="button"
                    wire:click="setRange('{{ $option['value'] }}')"
                    aria-pressed="{{ $range === $option['value'] ? 'true' : 'false' }}"
                    class="text-xs px-3 py-1.5 rounded-lg border-2 {{ $range === $option['value'] ? 'bg-accent/15 border-accent text-text' : 'bg-surface border-surface-border text-text/65' }}"
                >
                    {{ $option['label'] }}
                </button>
            @endforeach
        </div>
    </div>

    @if ($range === 'custom')
        <div class="flex flex-wrap items-end gap-3 w-full">
            <label class="flex flex-col gap-1 text-xs text-text/65">
                From
                <input type="date" wire:model.live="from" class="text-sm" />
            </label>
            <label class="flex flex-col gap-1 text-xs text-text/65">
                To
                <input type="date" wire:model.live="to" class="text-sm" />
            </label>
        </div>
    @endif

    @if ($this->totals->pageviews === 0)
        <div class="w-full bg-surface border-2 border-surface-border rounded-lg p-6 text-center">
            <p class="text-sm text-text/80">
                No analytics recorded yet for this range. If you just deployed, run
                <code class="text-xs bg-bg/60 rounded px-1.5 py-0.5">php artisan analytics:rollup</code>
                to backfill past days, or wait for the hourly schedule to catch up.
            </p>
        </div>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 w-full">
        <x-analytics-stat label="Pageviews" :value="$this->totals->pageviews" :delta="$this->delta($this->totals->pageviews, $this->previousTotals->pageviews)" />
        <x-analytics-stat label="Daily visitors (summed)" :value="$this->totals->visitors" :delta="$this->delta($this->totals->visitors, $this->previousTotals->visitors)" />
        <x-analytics-stat label="Views per visitor" :value="number_format($this->totals->viewsPerVisitor, 2)" />
    </div>

    <p class="text-xs text-text/50 -mt-3 self-start">
        "Daily visitors (summed)": someone who visits on three days counts three times. Visitor counts
        are only deduplicated within a single day, by design -- we don't keep anything that would let
        us recognise a returning visitor.
    </p>

    <div class="flex flex-col gap-2 w-full bg-surface border-2 border-surface-border rounded-lg p-5">
        <div class="flex items-center gap-4 text-xs text-text/65">
            <span class="flex items-center gap-1.5"><span class="inline-block w-3 h-0.5 bg-accent" aria-hidden="true"></span> Pageviews</span>
            <span class="flex items-center gap-1.5"><span class="inline-block w-3 h-0.5 bg-text/40" style="border-top: 2px dashed currentColor;" aria-hidden="true"></span> Visitors</span>
        </div>

        <div class="flex gap-2">
            <div class="flex flex-col justify-between text-xs text-text/50 h-56 text-right" style="font-variant-numeric: tabular-nums;" aria-hidden="true">
                @foreach ($this->chart['yTicks'] as $tick)
                    <span>{{ $tick['value'] }}</span>
                @endforeach
            </div>

            <svg viewBox="0 0 {{ $this->chart['width'] }} {{ $this->chart['height'] }}" preserveAspectRatio="none" class="w-full h-56 flex-1" role="img" aria-label="Pageviews and visitors over the selected range">
                @foreach ($this->chart['yTicks'] as $tick)
                    <line x1="0" x2="{{ $this->chart['width'] }}" y1="{{ $tick['y'] }}" y2="{{ $tick['y'] }}" stroke="currentColor" class="text-text" stroke-opacity="0.12" stroke-width="1" vector-effect="non-scaling-stroke" />
                @endforeach
                <polyline points="{{ $this->chart['pageviewPoints'] }}" fill="none" stroke="var(--accent)" stroke-width="2" vector-effect="non-scaling-stroke" />
                <polyline points="{{ $this->chart['visitorPoints'] }}" fill="none" stroke="currentColor" class="text-text" stroke-opacity="0.4" stroke-width="2" stroke-dasharray="6 4" vector-effect="non-scaling-stroke" />
            </svg>
        </div>

        <div class="flex justify-between text-xs text-text/50 ps-10">
            @foreach ($this->chart['labels'] as $tick)
                <span>{{ $tick['label'] }}</span>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 w-full">
        <x-analytics-breakdown
            title="Pages"
            column-label="Page"
            panel-key="pages"
            :rows="$this->topPages"
            :expanded="in_array('pages', $expandedPanels, true)"
            empty-message="No pageviews yet."
        />
        <x-analytics-breakdown
            title="Countries"
            column-label="Country"
            panel-key="countries"
            :rows="$this->countries"
            :expanded="in_array('countries', $expandedPanels, true)"
            empty-message="No visits recorded yet."
        />
        <x-analytics-breakdown
            title="Regions"
            column-label="Region"
            panel-key="regions"
            :rows="$this->regions"
            :expanded="in_array('regions', $expandedPanels, true)"
            empty-message="No region data yet. Not every visit resolves to a state/province."
        />
        <x-analytics-breakdown
            title="Referrers"
            column-label="Referrer"
            panel-key="referrers"
            :rows="$this->referrers"
            :expanded="in_array('referrers', $expandedPanels, true)"
            empty-message="No referrers yet. Links from other sites will appear here."
        />
        <x-analytics-breakdown
            title="Campaigns"
            column-label="Source"
            panel-key="campaigns"
            :rows="$this->utmSources"
            :expanded="in_array('campaigns', $expandedPanels, true)"
            empty-message="No campaign traffic yet. Links with a utm_source parameter will appear here."
        />
    </div>
</div>
