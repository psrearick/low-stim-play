<?php

use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Services\Stats\PunchlineStatsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public GameParticipant $participant;

    public function mount(GameParticipant $gameParticipant): void
    {
        // 404, not 403 -- mirrors profile.apple-match.show/profile.family-feud.show
        // exactly.
        abort_unless($gameParticipant->user_id === Auth::id(), 404);
        abort_unless($gameParticipant->gameSession->type === GameType::Punchline, 404);

        $this->participant = $gameParticipant;
    }

    public function getStatsProperty(): array
    {
        return app(PunchlineStatsService::class)->statsFor($this->participant);
    }
};
?>

<x-slot:title>Punchline Game — Low Stim Play</x-slot>
<x-slot:sidebar>1</x-slot:sidebar>

<div class="flex flex-col items-center gap-6 w-full max-w-2xl">
    <a href="{{ route('profile.punchline') }}" class="self-start text-sm text-text/65 hover:text-text">&larr; Punchline Stats</a>

    <div class="flex flex-col items-center gap-1">
        <h1 class="text-bar font-bold tracking-wide text-2xl text-center">Punchline Game</h1>
        <p class="text-sm text-text/65">
            {{ $participant->created_at->format('M j, Y g:ia') }} &middot; {{ $participant->gameSession->mode->label() }}
        </p>
    </div>

    <div class="flex flex-wrap justify-center gap-3 w-full">
        <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
            <span class="text-2xl font-bold text-text">{{ $this->stats['score'] }}</span>
            <span class="text-xs text-text/65">Score</span>
        </div>
        <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
            <span class="text-2xl font-bold text-text">{{ $participant->result ? ucfirst($participant->result->value) : '—' }}</span>
            <span class="text-xs text-text/65">Result</span>
        </div>
        <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
            <span class="text-2xl font-bold text-text">{{ $this->stats['sweepsWon'] }}</span>
            <span class="text-xs text-text/65">Sweeps won</span>
        </div>
    </div>

    <div class="flex flex-col gap-2 w-full">
        <p class="text-sm text-text/80 font-bold">Points by round</p>
        <div class="flex justify-center gap-3 w-full">
            @foreach ($this->stats['pointsByRound'] as $index => $points)
                <div wire:key="round-{{ $index }}" class="flex flex-col items-center gap-1 bg-surface border-2 border-surface-border rounded-lg px-5 py-3 flex-1 basis-24">
                    <span class="text-lg font-bold text-text">{{ $points }}</span>
                    <span class="text-xs text-text/65">Round {{ $index + 1 }}</span>
                </div>
            @endforeach
        </div>
    </div>
</div>
