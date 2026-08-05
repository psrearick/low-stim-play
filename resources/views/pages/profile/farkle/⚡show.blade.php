<?php

use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Services\Stats\FarkleStatsService;
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
        abort_unless($gameParticipant->gameSession->type === GameType::Farkle, 404);

        $this->participant = $gameParticipant;
    }

    public function getStatsProperty(): array
    {
        return app(FarkleStatsService::class)->statsFor($this->participant);
    }
};
?>

<x-slot:title>Farkle Game — Low Stim Play</x-slot>
<x-slot:sidebar>1</x-slot:sidebar>

<div class="flex flex-col items-center gap-6 w-full max-w-2xl">
    <a href="{{ route('profile.farkle') }}" class="self-start text-sm text-text/65 hover:text-text">&larr; Farkle Stats</a>

    <div class="flex flex-col items-center gap-1">
        <h1 class="text-bar font-bold tracking-wide text-2xl text-center">Farkle Game</h1>
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
        <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
            <span class="text-2xl font-bold text-text">{{ $this->stats['bestTurnScore'] }}</span>
            <span class="text-xs text-text/65">Best turn</span>
        </div>
        <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
            <span class="text-2xl font-bold text-text">{{ $this->stats['hotDiceCount'] }}</span>
            <span class="text-xs text-text/65">Hot dice</span>
        </div>
        <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
            <span class="text-2xl font-bold text-text">{{ $this->stats['farkleCount'] }}</span>
            <span class="text-xs text-text/65">Farkles</span>
        </div>
    </div>
</div>
