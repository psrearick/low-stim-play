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
     * other row here is always freely replayable.
     *
     * @return list<Carbon>
     */
    public function getDaysProperty(): array
    {
        return collect(range(0, self::DAYS - 1))
            ->map(fn (int $daysAgo) => now()->subDays($daysAgo)->startOfDay())
            ->all();
    }
};
?>

<x-slot:title>Daily Puzzles — Low Stim Play</x-slot>
<x-slot:description>Play today's Word Grid and Word Blocks daily puzzle, or catch up on a past day from the archive.</x-slot:description>

<div class="flex flex-col items-center w-full gap-6" style="max-width: 640px;">
    <div class="heading">Daily Puzzles</div>

    <p class="text-sm text-text/65 text-center">
        A new puzzle every day for Word Grid and Word Blocks — everyone gets the same board. Signed in, each day can only be played once; browse back through the archive any time.
    </p>

    <div class="w-full overflow-x-auto bg-surface border-2 border-surface-border rounded-lg">
        <div class="grid min-w-[420px]" style="grid-template-columns: 1fr 1fr 1fr;">
            @foreach ($this->days as $date)
                <div wire:key="day-{{ $date->toDateString() }}" class="contents">
                    <span class="pl-6 pr-3 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm text-text">
                        {{ $date->isToday() ? 'Today' : $date->format('M j, Y') }}
                    </span>
                    <span class="px-3 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm">
                        <a href="{{ route('boggle.play', ['dailyDate' => $date->toDateString()]) }}" id="daily-boggle-{{ $date->toDateString() }}">Word Grid</a>
                    </span>
                    <span class="pl-3 pr-6 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm">
                        <a href="{{ route('quartiles.play', ['dailyDate' => $date->toDateString()]) }}" id="daily-quartiles-{{ $date->toDateString() }}">Word Blocks</a>
                    </span>
                </div>
            @endforeach
        </div>
    </div>
</div>
