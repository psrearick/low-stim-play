@props(['label', 'value', 'delta' => null])

<div class="flex flex-col gap-1 bg-surface border-2 border-surface-border rounded-lg p-5">
    <p class="text-xs text-text/65 font-bold">{{ $label }}</p>
    <p class="text-2xl font-bold text-text" style="font-variant-numeric: tabular-nums;">{{ $value }}</p>

    @if ($delta === null)
        <p class="text-sm text-text/50">No prior period to compare</p>
    @else
        <p class="text-sm {{ $delta >= 0 ? 'text-accent' : 'text-warn' }}" style="font-variant-numeric: tabular-nums;">
            <span aria-hidden="true">{{ $delta >= 0 ? '▲' : '▼' }}</span>
            {{ $delta >= 0 ? '+' : '' }}{{ $delta }}% vs previous period
        </p>
    @endif
</div>
