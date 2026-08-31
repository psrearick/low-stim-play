<?php

use App\Models\User;
use App\Services\Stats\PipsStatsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public function getGamesProperty()
    {
        return app(PipsStatsService::class)->gamesForUser($this->user());
    }

    /**
     * @return array{easy: list<array{label: string, value: string, participant: \App\Models\GameParticipant}>, medium: list<array{label: string, value: string, participant: \App\Models\GameParticipant}>, hard: list<array{label: string, value: string, participant: \App\Models\GameParticipant}>}
     */
    public function getBestMetricsByDifficultyProperty(): array
    {
        return app(PipsStatsService::class)->bestMetricsByDifficulty($this->user());
    }

    /**
     * @return array{easy: ?float, medium: ?float, hard: ?float}
     */
    public function getWinRateByDifficultyProperty(): array
    {
        return app(PipsStatsService::class)->winRateByDifficulty($this->user());
    }

    public function statsFor($participant): array
    {
        return app(PipsStatsService::class)->statsFor($participant);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
};
?>

<x-slot:title>Pips Stats — Low Stim Play</x-slot>
<x-slot:sidebar>1</x-slot:sidebar>

<div class="flex flex-col items-center gap-6 w-full max-w-2xl">
    <h1 class="text-bar font-bold tracking-wide text-2xl text-center">Pips Stats</h1>

    @if ($this->games->isEmpty())
        <p class="text-sm text-text/65 text-center">No Pips games recorded yet.</p>
    @else
        {{--
            Split by difficulty tier, not blended -- same "same metric,
            non-comparable scales across categories" reasoning as
            profile.boggle's own solo/room split (docs/DEVLOG.md's Task 27
            entry), extended from two categories to three. A tier with no
            games yet is omitted entirely, not shown as an empty section.
        --}}
        @foreach (['easy' => 'Easy', 'medium' => 'Medium', 'hard' => 'Hard'] as $tier => $label)
            @if (! empty($this->bestMetricsByDifficulty[$tier]) || $this->winRateByDifficulty[$tier] !== null)
                <div class="flex flex-col gap-2 w-full">
                    <p class="text-sm text-text/80 font-bold">Personal bests ({{ $label }})</p>
                    <div class="flex flex-wrap justify-center gap-3 w-full">
                        @foreach ($this->bestMetricsByDifficulty[$tier] as $metric)
                            <a wire:key="best-{{ $tier }}-{{ $loop->index }}"
                                href="{{ route('profile.pips.show', $metric['participant']) }}"
                                class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-40">
                                <span class="text-2xl font-bold text-accent">{{ $metric['value'] }}</span>
                                <span class="text-xs text-text/65 text-center">{{ $metric['label'] }}</span>
                            </a>
                        @endforeach

                        @if ($this->winRateByDifficulty[$tier] !== null)
                            <div wire:key="win-rate-{{ $tier }}" class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-40">
                                <span class="text-2xl font-bold text-accent">{{ $this->winRateByDifficulty[$tier] }}%</span>
                                <span class="text-xs text-text/65 text-center">Win rate</span>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        @endforeach

        <div class="flex flex-col gap-2 w-full">
            <p class="text-sm text-text/80 font-bold">All games</p>

            {{--
                A real grid, not flex-wrap -- see profile.boggle's own index
                page for why (each row's `<a>` uses `display: contents` so
                its cells become direct grid items, keeping columns
                aligned like a table).
            --}}
            <div class="w-full overflow-x-auto bg-surface border-2 border-surface-border rounded-lg">
                <div class="grid min-w-[560px]" style="grid-template-columns: 1.3fr 0.8fr 0.8fr 0.9fr;">
                    <span class="pl-6 pr-3 py-4 text-xs text-text/65 font-bold">Date</span>
                    <span class="px-3 py-4 text-xs text-text/65 font-bold">Difficulty</span>
                    <span class="px-3 py-4 text-xs text-text/65 font-bold">Score</span>
                    <span class="pl-3 pr-6 py-4 text-xs text-text/65 font-bold">Result</span>

                    @foreach ($this->games as $participant)
                        @php $stats = $this->statsFor($participant) @endphp
                        <a wire:key="game-{{ $participant->id }}" href="{{ route('profile.pips.show', $participant) }}" class="contents group">
                            <span class="pl-6 pr-3 py-3 border-t border-surface-border text-sm text-text/65 group-hover:bg-bg/60">{{ $participant->created_at->diffForHumans() }}</span>
                            <span class="px-3 py-3 border-t border-surface-border text-sm text-text/80 group-hover:bg-bg/60">{{ $stats['difficulty'] ? ucfirst($stats['difficulty']) : '—' }}</span>
                            <span class="px-3 py-3 border-t border-surface-border text-sm text-text group-hover:bg-bg/60">{{ $participant->score }}</span>
                            <span class="pl-3 pr-6 py-3 border-t border-surface-border text-sm text-text/65 group-hover:bg-bg/60">{{ $stats['solved'] ? 'Solved' : 'Abandoned' }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
