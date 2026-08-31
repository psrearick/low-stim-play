<?php

use Illuminate\Support\Carbon;
use Livewire\Component;

new class extends Component
{
    private const DAYS = 30;

    /**
     * Today down through 29 days ago -- every entry is pure date math (no
     * DB reads), since DailyPuzzle::seedFor() derives each day's puzzle
     * deterministically from the date alone. Signed in, today's row is the
     * one-play-per-day lock (see quartiles/boggle play() mount()); every
     * other row here is always freely replayable. Today itself gets its
     * own prominent card above the archive table rather than just being
     * this list's first row -- see the template below.
     *
     * @return \Illuminate\Support\Collection<int, Carbon>
     */
    public function getDaysProperty(): \Illuminate\Support\Collection
    {
        return collect(range(0, self::DAYS - 1))
            ->map(fn (int $daysAgo) => now()->subDays($daysAgo)->startOfDay());
    }
};
?>

<x-slot:title>Daily Puzzles — Low Stim Play</x-slot>
<x-slot:description>Play today's Word Grid, Quads, Five Letters, Word Hive, and Pips daily puzzle, or catch up on a past day from the archive.</x-slot:description>

<div class="flex flex-col items-center w-full gap-6" style="max-width: 640px;">
    <div class="heading">Daily Puzzles</div>

    <p class="text-sm text-text/65 text-center">
        A new puzzle every day for Word Grid, Quads, Five Letters, Word Hive, and Pips — everyone gets the same board. Signed in, each day can only be played once (Pips locks each difficulty separately); browse back through the archive any time.
    </p>

    {{--
        Pulled out of the archive table below and given its own prominent
        card -- burying "today" as just the first row of a 30-row list is
        the whole reason this page wasn't actually reachable/usable before
        (there was no site-wide link to it either, now fixed in
        layouts/app.blade.php -- see docs/DEVLOG.md).
    --}}
    <div class="flex flex-col items-center gap-3 w-full bg-surface border-2 border-accent rounded-lg p-6">
        <p class="text-sm font-bold text-accent">Today — {{ now()->format('M j, Y') }}</p>
        <div class="flex flex-wrap justify-center gap-3">
            <a href="{{ route('boggle.play', ['dailyDate' => now()->toDateString()]) }}" id="daily-boggle-today">
                <button type="button">Play Today's Word Grid</button>
            </a>
            <a href="{{ route('quartiles.play', ['dailyDate' => now()->toDateString()]) }}" id="daily-quartiles-today">
                <button type="button">Play Today's Quads</button>
            </a>
            <a href="{{ route('wordle.play', ['dailyDate' => now()->toDateString()]) }}" id="daily-wordle-today">
                <button type="button">Play Today's Five Letters</button>
            </a>
            <a href="{{ route('spelling-bee.play', ['dailyDate' => now()->toDateString()]) }}" id="daily-spelling-bee-today">
                <button type="button">Play Today's Word Hive</button>
            </a>
            <a href="{{ route('pips.play', ['dailyDate' => now()->toDateString(), 'difficulty' => 'easy']) }}" id="daily-pips-easy-today">
                <button type="button">Play Today's Pips (Easy)</button>
            </a>
            <a href="{{ route('pips.play', ['dailyDate' => now()->toDateString(), 'difficulty' => 'medium']) }}" id="daily-pips-medium-today">
                <button type="button">Play Today's Pips (Medium)</button>
            </a>
            <a href="{{ route('pips.play', ['dailyDate' => now()->toDateString(), 'difficulty' => 'hard']) }}" id="daily-pips-hard-today">
                <button type="button">Play Today's Pips (Hard)</button>
            </a>
        </div>
    </div>

    <div class="flex flex-col gap-2 w-full">
        <p class="text-sm text-text/80 font-bold">Archive</p>
        {{--
            Pips gets one column (not three) -- three difficulty tiers
            each getting their own inline link inside it, rather than
            three separate table columns -- keeps this already-wide
            scrolling table from growing to 7 columns (docs/specs/pips.md
            section 4's daily wiring).
        --}}
        <div class="w-full overflow-x-auto bg-surface border-2 border-surface-border rounded-lg">
            <div class="grid min-w-[820px]" style="grid-template-columns: 1fr 1fr 1fr 1fr 1fr 1.4fr;">
                @foreach ($this->days->skip(1) as $date)
                    <div wire:key="day-{{ $date->toDateString() }}" class="contents">
                        <span class="pl-6 pr-3 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm text-text">
                            {{ $date->format('M j, Y') }}
                        </span>
                        <span class="px-3 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm">
                            <a href="{{ route('boggle.play', ['dailyDate' => $date->toDateString()]) }}" id="daily-boggle-{{ $date->toDateString() }}">Word Grid</a>
                        </span>
                        <span class="px-3 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm">
                            <a href="{{ route('quartiles.play', ['dailyDate' => $date->toDateString()]) }}" id="daily-quartiles-{{ $date->toDateString() }}">Quads</a>
                        </span>
                        <span class="px-3 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm">
                            <a href="{{ route('wordle.play', ['dailyDate' => $date->toDateString()]) }}" id="daily-wordle-{{ $date->toDateString() }}">Five Letters</a>
                        </span>
                        <span class="px-3 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm">
                            <a href="{{ route('spelling-bee.play', ['dailyDate' => $date->toDateString()]) }}" id="daily-spelling-bee-{{ $date->toDateString() }}">Word Hive</a>
                        </span>
                        <span class="pl-3 pr-6 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm flex flex-wrap gap-x-2">
                            <a href="{{ route('pips.play', ['dailyDate' => $date->toDateString(), 'difficulty' => 'easy']) }}" id="daily-pips-easy-{{ $date->toDateString() }}">Easy</a>
                            <a href="{{ route('pips.play', ['dailyDate' => $date->toDateString(), 'difficulty' => 'medium']) }}" id="daily-pips-medium-{{ $date->toDateString() }}">Medium</a>
                            <a href="{{ route('pips.play', ['dailyDate' => $date->toDateString(), 'difficulty' => 'hard']) }}" id="daily-pips-hard-{{ $date->toDateString() }}">Hard</a>
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
