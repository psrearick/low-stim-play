<?php

use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Services\Stats\BoggleStatsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public GameParticipant $participant;

    public function mount(GameParticipant $gameParticipant): void
    {
        // 404, not 403 -- this also covers someone else's participant id,
        // and either way the existence of another user's game is nobody
        // else's business.
        abort_unless($gameParticipant->user_id === Auth::id(), 404);
        abort_unless($gameParticipant->gameSession->type === GameType::Boggle, 404);

        $this->participant = $gameParticipant;
    }

    public function getStatsProperty(): array
    {
        return app(BoggleStatsService::class)->statsFor($this->participant);
    }

    /**
     * Found words grouped by length, ascending -- same shape as the in-game
     * reveal panel's own wordsByLength (see pages/boggle/⚡play.blade.php).
     *
     * @return array<int, list<array{word: string, points: int}>>
     */
    public function getWordsByLengthProperty(): array
    {
        $groups = [];

        foreach ($this->participant->stats['found_words'] ?? [] as $word => $points) {
            $groups[mb_strlen($word)][] = ['word' => $word, 'points' => $points];
        }

        ksort($groups);

        return $groups;
    }
};
?>

<x-slot:title>Boggle Game — Low Stim Play</x-slot>
<x-slot:sidebar>1</x-slot:sidebar>

<div class="flex flex-col items-center gap-6 w-full max-w-2xl">
    <a href="{{ route('profile.boggle') }}" class="self-start text-sm text-text/65 hover:text-text">&larr; Boggle Stats</a>

    <div class="flex flex-col items-center gap-1">
        <h1 class="text-bar font-bold tracking-wide text-2xl text-center">Boggle Game</h1>
        <p class="text-sm text-text/65">
            {{ $participant->created_at->format('M j, Y g:ia') }} &middot; {{ $participant->gameSession->mode->label() }}
        </p>
    </div>

    <div class="flex flex-wrap justify-center gap-3 w-full">
        {{--
            Only online-room games ever get a result -- solo/practice play
            has no opponent to win or lose against, so `result` stays null
            there (see docs/DEVLOG.md's Boggle win-rate entry) and this
            tile is simply omitted rather than showing a misleading dash.
        --}}
        @if ($participant->result)
            <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
                <span class="text-2xl font-bold {{ $participant->result === \App\Enums\GameResult::Win ? 'text-accent' : 'text-text' }}">{{ ucfirst($participant->result->value) }}</span>
                <span class="text-xs text-text/65">Result</span>
            </div>
        @endif
        <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
            <span class="text-2xl font-bold text-text">{{ $participant->score }}</span>
            <span class="text-xs text-text/65">Score</span>
        </div>
        <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
            <span class="text-2xl font-bold text-text">{{ $this->stats['wordsFound'] }}</span>
            <span class="text-xs text-text/65">Words found</span>
        </div>
        <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
            <span class="text-2xl font-bold text-text">{{ $this->stats['longestWord'] ? strtoupper($this->stats['longestWord']) : '—' }}</span>
            <span class="text-xs text-text/65">Longest word</span>
        </div>
        <div class="flex flex-col items-center gap-2 bg-surface border-2 border-surface-border rounded-lg px-5 py-4 flex-1 basis-32">
            <span class="text-2xl font-bold text-text">{{ $this->stats['percentFound'] !== null ? $this->stats['percentFound'].'%' : '—' }}</span>
            <span class="text-xs text-text/65">Of the board found</span>
        </div>
    </div>

    @if ($this->stats['byLength'] !== [])
        <div class="flex flex-col gap-2 w-full">
            <p class="text-sm text-text/80 font-bold">Words found by length</p>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->stats['byLength'] as $length => $count)
                    <span wire:key="length-{{ $length }}" class="text-sm bg-surface border-2 border-surface-border rounded-lg px-4 py-2 text-text">
                        {{ $length }} letters: <span class="font-bold">{{ $count }}</span>
                    </span>
                @endforeach
            </div>
        </div>
    @endif

    <div class="flex flex-col gap-2 w-full">
        <p class="text-sm text-text/80 font-bold">Words found</p>

        @if ($this->wordsByLength === [])
            <p class="text-sm text-text/65 text-center">No words found this game.</p>
        @else
            <div class="flex flex-wrap gap-6 w-full bg-surface border-2 border-surface-border rounded-lg p-6">
                @foreach ($this->wordsByLength as $length => $words)
                    <div class="flex flex-col gap-1 min-w-24">
                        <p class="text-xs text-text/65 font-bold border-b border-surface-border pb-2 mb-1">{{ $length }} letters</p>
                        <ul class="flex flex-col gap-1">
                            @foreach ($words as $entry)
                                <li wire:key="word-{{ $entry['word'] }}" class="text-sm text-text px-2 py-1">
                                    {{ strtoupper($entry['word']) }} <span class="text-text/65">({{ $entry['points'] }})</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
