<?php

use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Services\Stats\WordleStatsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public GameParticipant $participant;

    public function mount(GameParticipant $gameParticipant): void
    {
        // 404, not 403 -- same reasoning as pages::profile.quartiles.show:
        // this also covers someone else's participant id, and either way
        // the existence of another user's game is nobody else's business.
        abort_unless($gameParticipant->user_id === Auth::id(), 404);
        abort_unless($gameParticipant->gameSession->type === GameType::Wordle, 404);

        $this->participant = $gameParticipant;
    }

    public function getStatsProperty(): array
    {
        return app(WordleStatsService::class)->statsFor($this->participant);
    }

    /**
     * @return list<array{word: string, result: list<string>}>
     */
    public function getGuessesProperty(): array
    {
        return $this->participant->stats['guesses'] ?? [];
    }
};
?>

<x-slot:title>Five Letters Game — Low Stim Play</x-slot>
<x-slot:sidebar>1</x-slot:sidebar>

<div class="flex flex-col items-center gap-6 w-full max-w-2xl">
    <a href="{{ route('profile.wordle') }}" class="self-start text-sm text-text/65 hover:text-text">&larr; Five Letters Stats</a>

    <div class="flex flex-col items-center gap-1">
        <h1 class="text-bar font-bold tracking-wide text-2xl text-center">Five Letters Game</h1>
        <p class="text-sm text-text/65">{{ $participant->created_at->format('M j, Y g:ia') }}</p>
    </div>

    <div class="flex flex-wrap justify-center gap-3 w-full">
        <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
            <span class="text-2xl font-bold text-text">{{ $participant->score }}</span>
            <span class="text-xs text-text/65">Score</span>
        </div>
        <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
            <span class="text-2xl font-bold {{ $this->stats['won'] ? 'text-accent' : 'text-text' }}">{{ $this->stats['won'] ? 'Won' : 'Lost' }}</span>
            <span class="text-xs text-text/65">Result</span>
        </div>
        <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
            <span class="text-2xl font-bold text-text">{{ $this->stats['guessCount'] ?? '—' }}</span>
            <span class="text-xs text-text/65">Guesses</span>
        </div>
        <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
            <span class="text-2xl font-bold text-text">{{ $this->stats['hardMode'] ? 'Yes' : 'No' }}</span>
            <span class="text-xs text-text/65">Hard Mode</span>
        </div>
    </div>

    <div class="flex flex-col gap-2 w-full items-center">
        <p class="text-sm text-text/80 font-bold self-start">Guesses</p>

        @if ($this->guesses === [])
            <p class="text-sm text-text/65 text-center">No guesses recorded for this game.</p>
        @else
            <div class="flex flex-col items-center gap-1.5" id="wordle-history-grid">
                @foreach ($this->guesses as $guess)
                    <div class="grid grid-cols-5 gap-1.5" wire:key="history-row-{{ $loop->index }}">
                        @foreach (str_split($guess['word']) as $i => $letter)
                            @php
                                $status = $guess['result'][$i];
                                $tileClasses = match ($status) {
                                    'correct' => 'bg-accent border-accent text-bg',
                                    'present' => 'bg-warn/20 border-warn border-dashed text-text',
                                    default => 'bg-die border-die-border text-text/50',
                                };
                            @endphp
                            <div wire:key="history-cell-{{ $loop->parent->index }}-{{ $i }}"
                                class="aspect-square w-12 flex items-center justify-center rounded-lg border-2 text-lg font-bold uppercase {{ $tileClasses }}">
                                {{ $letter }}
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
