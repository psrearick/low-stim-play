<?php

use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Services\Stats\SpellingBeeStatsService;
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
        abort_unless($gameParticipant->gameSession->type === GameType::SpellingBee, 404);

        $this->participant = $gameParticipant;
    }

    public function getStatsProperty(): array
    {
        return app(SpellingBeeStatsService::class)->statsFor($this->participant);
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

        // Highest-scoring first, alphabetical within a tie.
        usort($words, fn (array $a, array $b) => $b['points'] <=> $a['points'] ?: $a['word'] <=> $b['word']);

        return $words;
    }
};
?>

<x-slot:title>Word Hive Game — Low Stim Play</x-slot>
<x-slot:sidebar>1</x-slot:sidebar>

<div class="flex flex-col items-center gap-6 w-full max-w-2xl">
    <a href="{{ route('profile.spelling-bee') }}" class="self-start text-sm text-text/65 hover:text-text">&larr; Word Hive Stats</a>

    <div class="flex flex-col items-center gap-1">
        <h1 class="text-bar font-bold tracking-wide text-2xl text-center">Word Hive Game</h1>
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
            <span class="text-2xl font-bold {{ $this->stats['pangramsFound'] > 0 ? 'text-accent' : 'text-text' }}">{{ $this->stats['pangramsFound'] }}</span>
            <span class="text-xs text-text/65">Pangrams found</span>
        </div>
        <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
            <span class="text-2xl font-bold text-text">{{ $this->stats['rank'] ?? '—' }}</span>
            <span class="text-xs text-text/65">Rank reached</span>
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
