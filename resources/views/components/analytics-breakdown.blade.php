@props(['title', 'rows', 'emptyMessage', 'panelKey', 'expanded' => false, 'columnLabel' => 'Value'])

@php
    $max = max(1, $rows->max('pageviews') ?? 0);
    $visible = $expanded ? $rows : $rows->take(10);
@endphp

<div class="flex flex-col gap-3 bg-surface border-2 border-surface-border rounded-lg p-5">
    <h2 class="text-sm font-bold text-text">{{ $title }}</h2>

    @if ($rows->isEmpty())
        <p class="text-sm text-text/65">{{ $emptyMessage }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm" style="font-variant-numeric: tabular-nums;">
                <caption class="sr-only">{{ $title }}</caption>
                <thead>
                    <tr class="text-left text-xs text-text/65">
                        <th scope="col" class="pb-2 pr-2 font-bold">{{ $columnLabel }}</th>
                        <th scope="col" class="pb-2 pr-2 font-bold text-right">Pageviews</th>
                        <th scope="col" class="pb-2 font-bold text-right">Visitors</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($visible as $row)
                        @php $pct = round($row['pageviews'] / $max * 100, 1); @endphp
                        <tr
                            wire:key="{{ $panelKey }}-{{ $loop->index }}"
                            style="background: linear-gradient(to right, oklch(from var(--accent) l c h / 0.14) {{ $pct }}%, transparent {{ $pct }}%);"
                        >
                            <th scope="row" class="py-1 pr-2 text-left font-normal text-text">{{ $row['label'] }}</th>
                            <td class="py-1 pr-2 text-right text-text">{{ $row['pageviews'] }}</td>
                            <td class="py-1 text-right text-text/65">{{ $row['visitors'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($rows->count() > 10)
            <button
                type="button"
                wire:click="togglePanel('{{ $panelKey }}')"
                class="text-xs text-accent underline self-start"
            >
                {{ $expanded ? 'Show fewer' : 'Show all ('.$rows->count().')' }}
            </button>
        @endif
    @endif
</div>
