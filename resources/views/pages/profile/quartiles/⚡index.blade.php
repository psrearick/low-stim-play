<?php

use App\Models\User;
use App\Services\Stats\QuartilesStatsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public function getGamesProperty()
    {
        return app(QuartilesStatsService::class)->gamesForUser($this->user());
    }

    public function getBestMetricsProperty(): array
    {
        return app(QuartilesStatsService::class)->bestMetrics($this->user());
    }

    public function statsFor($participant): array
    {
        return app(QuartilesStatsService::class)->statsFor($participant);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
};
?>

<x-slot:title>Quads Stats — Low Stim Play</x-slot>
<x-slot:sidebar>1</x-slot:sidebar>

<div class="flex flex-col items-center gap-6 w-full max-w-2xl">
    <h1 class="text-bar font-bold tracking-wide text-2xl text-center">Quads Stats</h1>

    @if ($this->games->isEmpty())
        <p class="text-sm text-text/65 text-center">No Quads games recorded yet.</p>
    @else
        <div class="flex flex-col gap-2 w-full">
            <p class="text-sm text-text/80 font-bold">Personal bests</p>
            <div class="flex flex-wrap justify-center gap-3 w-full">
                @foreach ($this->bestMetrics as $metric)
                    <a wire:key="best-{{ $loop->index }}"
                        href="{{ route('profile.quartiles.show', $metric['participant']) }}"
                        class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-40">
                        <span class="text-2xl font-bold text-accent">{{ $metric['value'] }}</span>
                        <span class="text-xs text-text/65 text-center">{{ $metric['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        <div class="flex flex-col gap-2 w-full">
            <p class="text-sm text-text/80 font-bold">All games</p>

            {{--
                A real grid, not flex-wrap -- see profile.boggle's own index
                page for why (each row's `<a>` uses `display: contents` so
                its cells become direct grid items, keeping columns aligned
                like a table). No Mode/Result columns here, unlike most
                other games' own index pages -- Word Blocks is solo-only, so
                mode is always "Solo" and result is always null.
            --}}
            <div class="w-full overflow-x-auto bg-surface border-2 border-surface-border rounded-lg">
                <div class="grid min-w-[520px]" style="grid-template-columns: 1.3fr 0.8fr 0.8fr 0.8fr 0.9fr;">
                    <span class="pl-6 pr-3 py-4 text-xs text-text/65 font-bold">Date</span>
                    <span class="px-3 py-4 text-xs text-text/65 font-bold">Score</span>
                    <span class="px-3 py-4 text-xs text-text/65 font-bold">Words</span>
                    <span class="px-3 py-4 text-xs text-text/65 font-bold">Quads</span>
                    <span class="pl-3 pr-6 py-4 text-xs text-text/65 font-bold">% Found</span>

                    @foreach ($this->games as $participant)
                        @php $stats = $this->statsFor($participant) @endphp
                        <a wire:key="game-{{ $participant->id }}" href="{{ route('profile.quartiles.show', $participant) }}" class="contents group">
                            <span class="pl-6 pr-3 py-3 border-t border-surface-border text-sm text-text/65 group-hover:bg-bg/60">{{ $participant->created_at->diffForHumans() }}</span>
                            <span class="px-3 py-3 border-t border-surface-border text-sm text-text group-hover:bg-bg/60">{{ $participant->score }}</span>
                            <span class="px-3 py-3 border-t border-surface-border text-sm text-text/80 group-hover:bg-bg/60">{{ $stats['wordsFound'] }}</span>
                            <span class="px-3 py-3 border-t border-surface-border text-sm text-text/80 group-hover:bg-bg/60">{{ $stats['quartilesFound'] }}/5</span>
                            <span class="pl-3 pr-6 py-3 border-t border-surface-border text-sm text-text/65 group-hover:bg-bg/60">{{ $stats['percentFound'] !== null ? $stats['percentFound'].'%' : '—' }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
