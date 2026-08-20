<?php

use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Services\Stats\QuartilesStatsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public GameParticipant $participant;

    public function mount(GameParticipant $gameParticipant): void
    {
        // 404, not 403 -- same reasoning as pages::profile.boggle.show:
        // this also covers someone else's participant id, and either way
        // the existence of another user's game is nobody else's business.
        abort_unless($gameParticipant->user_id === Auth::id(), 404);
        abort_unless($gameParticipant->gameSession->type === GameType::Quartiles, 404);

        $this->participant = $gameParticipant;
    }

    public function getStatsProperty(): array
    {
        return app(QuartilesStatsService::class)->statsFor($this->participant);
    }

    /**
     * @return list<array{word: string, points: int}>
     */
    public function getFoundWordsProperty(): array
    {
        $words = [];

        foreach ($this->participant->stats['found_words'] ?? [] as $word => $points) {
            $words[] = ['word' => $word, 'points' => $points];
        }

        // Highest-scoring (most tiles) first, alphabetical within a tie.
        usort($words, fn (array $a, array $b) => $b['points'] <=> $a['points'] ?: $a['word'] <=> $b['word']);

        return $words;
    }
};
?>

<x-slot:title>Word Blocks Game — Low Stim Play</x-slot>
<x-slot:sidebar>1</x-slot:sidebar>

<div class="flex flex-col items-center gap-6 w-full max-w-2xl">
    <a href="{{ route('profile.quartiles') }}" class="self-start text-sm text-text/65 hover:text-text">&larr; Word Blocks Stats</a>

    <div class="flex flex-col items-center gap-1">
        <h1 class="text-bar font-bold tracking-wide text-2xl text-center">Word Blocks Game</h1>
        <p class="text-sm text-text/65">{{ $participant->created_at->format('M j, Y g:ia') }}</p>
    </div>

    <div class="flex flex-wrap justify-center gap-3 w-full">
        <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
            <span class="text-2xl font-bold text-text">{{ $participant->score }}</span>
            <span class="text-xs text-text/65">Score</span>
        </div>
        <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
            <span class="text-2xl font-bold text-text">{{ $this->stats['wordsFound'] }}</span>
            <span class="text-xs text-text/65">Words found</span>
        </div>
        <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
            <span class="text-2xl font-bold {{ $this->stats['quartilesFound'] === 5 ? 'text-accent' : 'text-text' }}">{{ $this->stats['quartilesFound'] }}/5</span>
            <span class="text-xs text-text/65">Quads found</span>
        </div>
        <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
            <span class="text-2xl font-bold text-text">{{ $this->stats['percentFound'] !== null ? $this->stats['percentFound'].'%' : '—' }}</span>
            <span class="text-xs text-text/65">Of the puzzle found</span>
        </div>
    </div>

    <div class="flex flex-col gap-2 w-full">
        <p class="text-sm text-text/80 font-bold">Words found</p>

        @if ($this->foundWords === [])
            <p class="text-sm text-text/65 text-center">No words found this game.</p>
        @else
            <div class="flex flex-wrap gap-2 w-full bg-surface border-2 border-surface-border rounded-lg p-6">
                @foreach ($this->foundWords as $entry)
                    <span wire:key="word-{{ $entry['word'] }}" class="text-sm bg-bg border border-surface-border rounded px-3 py-1.5 text-text">
                        {{ strtoupper($entry['word']) }} <span class="text-text/65">({{ $entry['points'] }})</span>
                    </span>
                @endforeach
            </div>
        @endif
    </div>
</div>
