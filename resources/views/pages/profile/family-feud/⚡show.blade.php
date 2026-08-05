<?php

use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Services\Stats\FamilyFeudStatsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public GameParticipant $participant;

    public function mount(GameParticipant $gameParticipant): void
    {
        // 404, not 403 -- mirrors profile.yahtzee.show/profile.boggle.show
        // exactly: this also covers someone else's participant id, and
        // either way the existence of another user's game is nobody
        // else's business.
        abort_unless($gameParticipant->user_id === Auth::id(), 404);
        abort_unless($gameParticipant->gameSession->type === GameType::FamilyFeud, 404);

        $this->participant = $gameParticipant;
    }

    public function getStatsProperty(): array
    {
        return app(FamilyFeudStatsService::class)->statsFor($this->participant);
    }
};
?>

<x-slot:title>Survey Says Game — Low Stim Play</x-slot>
<x-slot:sidebar>1</x-slot:sidebar>

<div class="flex flex-col items-center gap-6 w-full max-w-2xl">
    <a href="{{ route('profile.family-feud') }}" class="self-start text-sm text-text/65 hover:text-text">&larr; Survey Says Stats</a>

    <div class="flex flex-col items-center gap-1">
        <h1 class="text-bar font-bold tracking-wide text-2xl text-center">Survey Says Game</h1>
        <p class="text-sm text-text/65">
            {{ $participant->created_at->format('M j, Y g:ia') }} &middot; {{ $participant->gameSession->mode->label() }}
        </p>
    </div>

    <div class="flex flex-wrap justify-center gap-3 w-full">
        <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
            <span class="text-2xl font-bold text-text">{{ $participant->score }}</span>
            <span class="text-xs text-text/65">Score</span>
        </div>
        <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
            <span class="text-2xl font-bold text-text">{{ $participant->result ? ucfirst($participant->result->value) : '—' }}</span>
            <span class="text-xs text-text/65">Result</span>
        </div>
        @if ($this->stats['isCaptain'])
            <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
                <span class="text-2xl font-bold text-text">Yes</span>
                <span class="text-xs text-text/65">Captain</span>
            </div>
        @endif
    </div>

    @if ($this->stats['roundHistory'] !== [])
        <div class="flex flex-col gap-2 w-full">
            <p class="text-sm text-text/80 font-bold">Round-by-round</p>

            <div class="w-full bg-surface border-2 border-surface-border rounded-lg overflow-hidden">
                <div class="grid" style="grid-template-columns: 1fr auto;">
                    @foreach ($this->stats['roundHistory'] as $i => $points)
                        <span wire:key="round-label-{{ $i }}" class="pl-6 pr-3 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm text-text/80">Round {{ $i + 1 }}</span>
                        <span wire:key="round-points-{{ $i }}" class="pl-3 pr-6 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm text-text text-right">{{ $points }}</span>
                    @endforeach

                    <span class="pl-6 pr-3 py-3 border-t border-surface-border text-sm text-text font-bold">Total</span>
                    <span class="pl-3 pr-6 py-3 border-t border-surface-border text-sm text-text font-bold text-right">{{ $participant->score }}</span>
                </div>
            </div>
        </div>
    @endif
</div>
