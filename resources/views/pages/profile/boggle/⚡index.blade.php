<?php

use App\Models\User;
use App\Services\Stats\BoggleStatsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public function getGamesProperty()
    {
        return app(BoggleStatsService::class)->gamesForUser($this->user());
    }

    public function getBestMetricsProperty(): array
    {
        return app(BoggleStatsService::class)->bestMetrics($this->user());
    }

    public function getTopLongestWordsProperty(): array
    {
        return app(BoggleStatsService::class)->topLongestWords($this->user());
    }

    public function getTopFrequentWordsProperty(): array
    {
        return app(BoggleStatsService::class)->topFrequentWords($this->user());
    }

    public function statsFor($participant): array
    {
        return app(BoggleStatsService::class)->statsFor($participant);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
};
?>

<x-slot:title>Word Grid Stats — Low Stim Play</x-slot>
<x-slot:sidebar>1</x-slot:sidebar>

<div class="flex flex-col items-center gap-6 w-full max-w-2xl">
    <h1 class="text-bar font-bold tracking-wide text-2xl text-center">Word Grid Stats</h1>

    @if ($this->games->isEmpty())
        <p class="text-sm text-text/65 text-center">No Word Grid games recorded yet.</p>
    @else
        <div class="flex flex-col gap-2 w-full">
            <p class="text-sm text-text/80 font-bold">Personal bests</p>
            <div class="flex flex-wrap justify-center gap-3 w-full">
                @foreach ($this->bestMetrics as $metric)
                    <a wire:key="best-{{ $loop->index }}"
                        href="{{ route('profile.boggle.show', $metric['participant']) }}"
                        class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-40">
                        <span class="text-2xl font-bold text-accent">{{ $metric['value'] }}</span>
                        <span class="text-xs text-text/65 text-center">{{ $metric['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        <div class="flex flex-col sm:flex-row gap-4 w-full">
            <div class="flex flex-col gap-2 flex-1">
                <p class="text-sm text-text/80 font-bold">Longest words found</p>
                <ol class="flex flex-col gap-1 bg-surface border-2 border-surface-border rounded-lg p-4">
                    @forelse ($this->topLongestWords as $entry)
                        <li wire:key="longest-{{ $entry['word'] }}" class="flex items-center justify-between gap-3 text-sm px-2 py-1.5">
                            <a href="{{ route('profile.boggle.show', $entry['participant']) }}" class="text-text hover:text-accent">
                                {{ strtoupper($entry['word']) }}
                            </a>
                            <span class="text-text/65">{{ $entry['length'] }}</span>
                        </li>
                    @empty
                        <li class="text-sm text-text/65 text-center px-2 py-1.5">Nothing here yet.</li>
                    @endforelse
                </ol>
            </div>

            <div class="flex flex-col gap-2 flex-1">
                <p class="text-sm text-text/80 font-bold">Most frequently found words</p>
                <ol class="flex flex-col gap-1 bg-surface border-2 border-surface-border rounded-lg p-4">
                    @forelse ($this->topFrequentWords as $entry)
                        <li wire:key="frequent-{{ $entry['word'] }}" class="flex items-center justify-between gap-3 text-sm px-2 py-1.5">
                            <a href="{{ route('profile.boggle.show', $entry['participant']) }}" class="text-text hover:text-accent">
                                {{ strtoupper($entry['word']) }}
                            </a>
                            <span class="text-text/65">&times;{{ $entry['count'] }}</span>
                        </li>
                    @empty
                        <li class="text-sm text-text/65 text-center px-2 py-1.5">Nothing here yet.</li>
                    @endforelse
                </ol>
            </div>
        </div>

        <div class="flex flex-col gap-2 w-full">
            <p class="text-sm text-text/80 font-bold">All games</p>

            {{--
                A real grid, not flex-wrap -- flex-wrap redistributes each
                row's items based on that row's own content, so the same
                column (e.g. "Mode") landed at a different x position per
                row depending on how long a neighboring value was. Every row
                below (including the header) shares this exact
                grid-template-columns, so columns line up like a table.
                Each game's row is a single `<a>` with `display: contents`
                (the `contents` class) so its cells become direct grid items
                of this container -- the anchor itself renders no box, but
                clicking anywhere in its cells still activates it, since
                hit-testing/`:hover` follow DOM ancestry, not box geometry.
            --}}
            <div class="w-full overflow-x-auto bg-surface border-2 border-surface-border rounded-lg">
                <div class="grid min-w-[640px]" style="grid-template-columns: 1.3fr 1.1fr 0.7fr 0.7fr 0.8fr 0.9fr 0.7fr;">
                    <span class="pl-6 pr-3 py-4 text-xs text-text/65 font-bold">Date</span>
                    <span class="px-3 py-4 text-xs text-text/65 font-bold">Mode</span>
                    <span class="px-3 py-4 text-xs text-text/65 font-bold">Result</span>
                    <span class="px-3 py-4 text-xs text-text/65 font-bold">Score</span>
                    <span class="px-3 py-4 text-xs text-text/65 font-bold">Words</span>
                    <span class="px-3 py-4 text-xs text-text/65 font-bold">Longest</span>
                    <span class="pl-3 pr-6 py-4 text-xs text-text/65 font-bold">% Found</span>

                    @foreach ($this->games as $participant)
                        @php $stats = $this->statsFor($participant) @endphp
                        <a wire:key="game-{{ $participant->id }}" href="{{ route('profile.boggle.show', $participant) }}" class="contents group">
                            <span class="pl-6 pr-3 py-3 border-t border-surface-border text-sm text-text/65 group-hover:bg-bg/60">{{ $participant->created_at->diffForHumans() }}</span>
                            <span class="px-3 py-3 border-t border-surface-border text-sm text-text/65 group-hover:bg-bg/60">{{ $participant->gameSession->mode->label() }}</span>
                            {{--
                                Only online-room games ever get a result --
                                solo/practice play has no opponent to win or
                                lose against, so `result` stays null there
                                (see docs/DEVLOG.md's Boggle win-rate entry).
                            --}}
                            <span class="px-3 py-3 border-t border-surface-border text-sm {{ $participant->result === \App\Enums\GameResult::Win ? 'text-accent' : 'text-text/65' }} group-hover:bg-bg/60">{{ $participant->result ? ucfirst($participant->result->value) : '—' }}</span>
                            <span class="px-3 py-3 border-t border-surface-border text-sm text-text group-hover:bg-bg/60">{{ $participant->score }}</span>
                            <span class="px-3 py-3 border-t border-surface-border text-sm text-text/80 group-hover:bg-bg/60">{{ $stats['wordsFound'] }}</span>
                            <span class="px-3 py-3 border-t border-surface-border text-sm text-text/80 group-hover:bg-bg/60">{{ $stats['longestWord'] ? strtoupper($stats['longestWord']) : '—' }}</span>
                            <span class="pl-3 pr-6 py-3 border-t border-surface-border text-sm text-text/65 group-hover:bg-bg/60">{{ $stats['percentFound'] !== null ? $stats['percentFound'].'%' : '—' }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
