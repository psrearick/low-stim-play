@props(['stats', 'recentGames', 'linkable' => false])

<div class="flex flex-wrap justify-center gap-4 w-full">
    @forelse ($stats as $stat)
        @php
            $statsRoute = match ($stat['type']) {
                \App\Enums\GameType::Boggle => 'profile.boggle',
                \App\Enums\GameType::Yahtzee => 'profile.yahtzee',
                \App\Enums\GameType::FamilyFeud => 'profile.family-feud',
            };
            $tag = $linkable && $statsRoute ? 'a' : 'div';
        @endphp
        <{{ $tag }}
            wire:key="stat-{{ $stat['type']->value }}"
            @if ($linkable && $statsRoute) href="{{ route($statsRoute) }}" @endif
            class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg p-5 flex-1 basis-40"
        >
            <p class="text-bar font-bold tracking-wide">{{ $stat['type']->label() }}</p>
            <p class="text-2xl font-bold text-text">{{ $stat['gamesPlayed'] }}</p>
            <p class="text-xs text-text/65">games played</p>
            <p class="text-sm text-text/80">{{ $stat['winRate'] }}% win rate</p>
            <p class="text-sm text-text/80">{{ $stat['avgScore'] }} avg score</p>
        </{{ $tag }}>
    @empty
        <p class="text-sm text-text/65 text-center">No games recorded yet.</p>
    @endforelse
</div>

{{--
    A real grid, not flex-wrap justify-between -- that redistributes each
    row's spans based on that row's own content, so e.g. "Score" landed at a
    different x position per row depending on how long the game type or
    result text next to it was. A fixed grid-template-columns, shared by
    every row, keeps each column aligned like a table instead.
--}}
<div class="flex flex-col gap-2 w-full">
    <p class="text-sm text-text/80 font-bold">Recent games</p>

    @if ($recentGames->isEmpty())
        <p class="text-sm text-text/65 text-center">Nothing here yet.</p>
    @else
        <div class="w-full bg-surface border-2 border-surface-border rounded-lg overflow-hidden">
            <div class="grid" style="grid-template-columns: 1fr 1.2fr 0.8fr 0.8fr;">
                @foreach ($recentGames as $participant)
                    <div wire:key="recent-{{ $participant->id }}" class="contents">
                        <span class="pl-6 pr-3 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm font-bold text-text">
                            {{ $participant->gameSession->type->label() }}
                        </span>
                        <span class="px-3 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm text-text/65">
                            {{ $participant->created_at->diffForHumans() }}
                        </span>
                        <span class="px-3 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm text-text">
                            {{ $participant->score }}
                        </span>
                        <span class="pl-3 pr-6 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm {{ $participant->result === \App\Enums\GameResult::Win ? 'text-accent' : 'text-text/65' }}">
                            {{ $participant->result ? ucfirst($participant->result->value) : '—' }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
