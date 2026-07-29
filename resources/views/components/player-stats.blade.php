@props(['stats', 'recentGames'])

<div class="flex flex-wrap justify-center gap-4 w-full">
    @forelse ($stats as $stat)
        <div wire:key="stat-{{ $stat['type']->value }}" class="flex flex-col items-center gap-1 bg-surface border-2 border-surface-border rounded-lg p-4 flex-1 basis-40">
            <p class="text-accent font-bold tracking-wide">{{ $stat['type']->label() }}</p>
            <p class="text-2xl font-bold text-text">{{ $stat['gamesPlayed'] }}</p>
            <p class="text-xs text-text/65">games played</p>
            <p class="text-sm text-text/80">{{ $stat['winRate'] }}% win rate</p>
            <p class="text-sm text-text/80">{{ $stat['avgScore'] }} avg score</p>
        </div>
    @empty
        <p class="text-sm text-text/65 text-center">No games recorded yet.</p>
    @endforelse
</div>

<div class="flex flex-col gap-2 w-full">
    <p class="text-sm text-text/80 font-bold">Recent games</p>
    <ul class="flex flex-col gap-2 w-full">
        @forelse ($recentGames as $participant)
            <li wire:key="recent-{{ $participant->id }}"
                class="flex items-center justify-between gap-3 bg-surface border-2 border-surface-border rounded-lg px-4 py-2 text-sm">
                <span class="font-bold text-text">{{ $participant->gameSession->type->label() }}</span>
                <span class="text-text/65">{{ $participant->created_at->diffForHumans() }}</span>
                <span class="text-text">Score: {{ $participant->score }}</span>
                @if ($participant->result)
                    <span class="{{ $participant->result === \App\Enums\GameResult::Win ? 'text-accent' : 'text-text/65' }}">
                        {{ ucfirst($participant->result->value) }}
                    </span>
                @endif
            </li>
        @empty
            <li class="text-sm text-text/65 text-center">Nothing here yet.</li>
        @endforelse
    </ul>
</div>
