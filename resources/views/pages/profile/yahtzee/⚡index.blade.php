<?php

use App\Models\User;
use App\Services\Stats\YahtzeeStatsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public function getGamesProperty()
    {
        return app(YahtzeeStatsService::class)->gamesForUser($this->user());
    }

    public function getBestMetricsProperty(): array
    {
        return app(YahtzeeStatsService::class)->bestMetrics($this->user());
    }

    public function getBestCategoryScoresProperty(): array
    {
        return app(YahtzeeStatsService::class)->bestCategoryScores($this->user());
    }

    public function statsFor($participant): array
    {
        return app(YahtzeeStatsService::class)->statsFor($participant);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
};
?>

<x-slot:title>Yahtzee Stats — Low Stim Play</x-slot>

<div class="flex flex-col items-center gap-6 w-full max-w-2xl">
    @teleport('#game-actions')
        <a href="{{ route('dashboard') }}"><button type="button">Dashboard</button></a>
    @endteleport

    <h1 class="text-bar font-bold tracking-wide text-2xl text-center">Yahtzee Stats</h1>

    @if ($this->games->isEmpty())
        <p class="text-sm text-text/65 text-center">No Yahtzee games recorded yet.</p>
    @else
        <div class="flex flex-col gap-2 w-full">
            <p class="text-sm text-text/80 font-bold">Personal bests</p>
            <div class="flex flex-wrap justify-center gap-3 w-full">
                @foreach ($this->bestMetrics as $metric)
                    <a wire:key="best-{{ $loop->index }}"
                        href="{{ route('profile.yahtzee.show', $metric['participant']) }}"
                        class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-40">
                        <span class="text-2xl font-bold text-accent">{{ $metric['value'] }}</span>
                        <span class="text-xs text-text/65 text-center">{{ $metric['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        <div class="flex flex-col gap-2 w-full">
            <p class="text-sm text-text/80 font-bold">Best category scores</p>
            <div class="w-full bg-surface border-2 border-surface-border rounded-lg overflow-hidden">
                <div class="grid" style="grid-template-columns: 1fr auto;">
                    @forelse ($this->bestCategoryScores as $entry)
                        <a wire:key="category-{{ $entry['category']->value }}" href="{{ route('profile.yahtzee.show', $entry['participant']) }}" class="contents group">
                            <span class="pl-6 pr-3 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm text-text group-hover:bg-bg/60">{{ $entry['category']->label() }}</span>
                            <span class="pl-3 pr-6 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm text-text/80 text-right group-hover:bg-bg/60">{{ $entry['score'] }}</span>
                        </a>
                    @empty
                        <span class="col-span-2 py-3 px-6 text-sm text-text/65 text-center">Nothing here yet.</span>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-2 w-full">
            <p class="text-sm text-text/80 font-bold">All games</p>

            {{--
                A real grid, not flex-wrap -- see profile.boggle's own index
                page for why (each row's `<a>` uses `display: contents` so
                its cells become direct grid items, keeping columns aligned
                like a table).
            --}}
            <div class="w-full overflow-x-auto bg-surface border-2 border-surface-border rounded-lg">
                <div class="grid min-w-[560px]" style="grid-template-columns: 1.3fr 1.1fr 0.8fr 0.7fr 0.9fr;">
                    <span class="pl-6 pr-3 py-4 text-xs text-text/65 font-bold">Date</span>
                    <span class="px-3 py-4 text-xs text-text/65 font-bold">Mode</span>
                    <span class="px-3 py-4 text-xs text-text/65 font-bold">Result</span>
                    <span class="px-3 py-4 text-xs text-text/65 font-bold">Score</span>
                    <span class="pl-3 pr-6 py-4 text-xs text-text/65 font-bold">Yahtzees</span>

                    @foreach ($this->games as $participant)
                        @php $stats = $this->statsFor($participant) @endphp
                        <a wire:key="game-{{ $participant->id }}" href="{{ route('profile.yahtzee.show', $participant) }}" class="contents group">
                            <span class="pl-6 pr-3 py-3 border-t border-surface-border text-sm text-text/65 group-hover:bg-bg/60">{{ $participant->created_at->diffForHumans() }}</span>
                            <span class="px-3 py-3 border-t border-surface-border text-sm text-text/65 group-hover:bg-bg/60">{{ $participant->gameSession->mode->label() }}</span>
                            <span class="px-3 py-3 border-t border-surface-border text-sm {{ $participant->result === \App\Enums\GameResult::Win ? 'text-accent' : 'text-text/65' }} group-hover:bg-bg/60">{{ $participant->result ? ucfirst($participant->result->value) : '—' }}</span>
                            <span class="px-3 py-3 border-t border-surface-border text-sm text-text group-hover:bg-bg/60">{{ $participant->score }}</span>
                            <span class="pl-3 pr-6 py-3 border-t border-surface-border text-sm text-text/80 group-hover:bg-bg/60">{{ $stats['yahtzeeBonusCount'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
