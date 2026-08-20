<?php

use App\Enums\GameResult;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\Stats\PlayerStatsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public function getGamesProperty()
    {
        return app(PlayerStatsService::class)->allGamesPaginated($this->user());
    }

    public function showRoute(GameParticipant $participant): string
    {
        $routeName = match ($participant->gameSession->type) {
            GameType::Boggle => 'profile.boggle.show',
            GameType::Yahtzee => 'profile.yahtzee.show',
            GameType::FamilyFeud => 'profile.family-feud.show',
            GameType::Farkle => 'profile.farkle.show',
            GameType::AppleMatch => 'profile.apple-match.show',
            GameType::ClearTheDeck => 'profile.clear-the-deck.show',
            GameType::Quartiles => 'profile.quartiles.show',
        };

        return route($routeName, $participant);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
};
?>

<x-slot:title>All Games — Low Stim Play</x-slot>
<x-slot:sidebar>1</x-slot:sidebar>

<div class="flex flex-col items-center gap-6 w-full max-w-2xl">
    <h1 class="text-bar font-bold tracking-wide text-2xl text-center">All Games</h1>

    @if ($this->games->isEmpty())
        <p class="text-sm text-text/65 text-center">No games recorded yet.</p>
    @else
        {{--
            overflow-x-auto + a min-width on the grid so this scrolls
            horizontally on narrow screens instead of squashing/clipping
            columns -- see docs/TODO.md's "stats tables not scrolling on
            mobile" item for the bug class this avoids from the start.
        --}}
        <div class="w-full overflow-x-auto bg-surface border-2 border-surface-border rounded-lg">
            <div class="grid min-w-[480px]" style="grid-template-columns: 1fr 1.2fr 0.8fr 0.8fr;">
                <span class="pl-6 pr-3 py-4 text-xs text-text/65 font-bold">Game</span>
                <span class="px-3 py-4 text-xs text-text/65 font-bold">Date</span>
                <span class="px-3 py-4 text-xs text-text/65 font-bold">Score</span>
                <span class="pl-3 pr-6 py-4 text-xs text-text/65 font-bold">Result</span>

                @foreach ($this->games as $participant)
                    <a wire:key="game-{{ $participant->id }}" href="{{ $this->showRoute($participant) }}" class="contents group">
                        <span class="pl-6 pr-3 py-3 border-t border-surface-border text-sm font-bold text-text group-hover:bg-bg/60">{{ $participant->gameSession->type->label() }}</span>
                        <span class="px-3 py-3 border-t border-surface-border text-sm text-text/65 group-hover:bg-bg/60">{{ $participant->created_at->diffForHumans() }}</span>
                        <span class="px-3 py-3 border-t border-surface-border text-sm text-text group-hover:bg-bg/60">{{ $participant->score }}</span>
                        <span class="pl-3 pr-6 py-3 border-t border-surface-border text-sm {{ $participant->result === GameResult::Win ? 'text-accent' : 'text-text/65' }} group-hover:bg-bg/60">{{ $participant->result ? ucfirst($participant->result->value) : '—' }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        @if ($this->games->hasPages())
            <div class="flex items-center justify-center gap-4">
                <button type="button" wire:click="previousPage" @disabled($this->games->onFirstPage())>Previous</button>
                <span class="text-sm text-text/65">Page {{ $this->games->currentPage() }} of {{ $this->games->lastPage() }}</span>
                <button type="button" wire:click="nextPage" @disabled(! $this->games->hasMorePages())>Next</button>
            </div>
        @endif
    @endif
</div>
