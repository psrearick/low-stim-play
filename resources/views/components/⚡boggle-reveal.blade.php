<?php

use Livewire\Component;

new class extends Component
{
    /**
     * @var array{size: int, seed: int, cells: list<array<string, mixed>>, neighbors: list<list<int>>}
     */
    public array $board = [];

    /**
     * @var array<string, int>
     */
    public array $foundWords = [];

    /**
     * @var list<string>
     */
    public array $allValidWords = [];

    public int $score = 0;

    /**
     * All valid words grouped by length, ascending.
     *
     * @return array<int, list<string>>
     */
    public function getWordsByLengthProperty(): array
    {
        $groups = [];

        foreach ($this->allValidWords as $word) {
            $groups[mb_strlen($word)][] = $word;
        }

        ksort($groups);

        return $groups;
    }
};
?>

<div class="flex flex-col items-center gap-5 w-full max-w-2xl bg-surface border-2 border-surface-border rounded-lg p-8">
    <p class="text-bar font-bold tracking-wide text-2xl">Final score: {{ $score }}</p>
    <p class="text-sm text-text/65">
        You found {{ count($foundWords) }} of {{ count($allValidWords) }} possible words.
    </p>

    <div class="flex flex-wrap gap-6 justify-center w-full">
        @foreach ($this->wordsByLength as $length => $words)
            <div class="flex flex-col gap-1">
                <p class="text-xs uppercase tracking-widest text-text/50">{{ $length }} letters</p>
                <ul class="flex flex-col gap-1">
                    @foreach ($words as $word)
                        <li wire:key="reveal-{{ $word }}"
                            class="text-sm {{ isset($foundWords[$word]) ? 'text-accent font-bold' : 'text-text/70' }}">
                            {{ strtoupper($word) }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</div>
