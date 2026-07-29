<?php

use App\Services\Boggle\BoardGenerator;
use App\Services\Boggle\Solver;
use Livewire\Component;

new class extends Component
{
    private const WIDE = ['W', 'M', 'Qu'];

    private const DESCENDER = ['Qu'];

    /**
     * @var list<int>
     */
    public array $sizeOptions = [4, 5];

    /**
     * @var list<int>
     */
    public array $minuteOptions = [1, 2, 3, 5];

    public int $size = 4;

    public int $minutes = 3;

    public bool $untimed = false;

    public bool $visualTimerOn = true;

    public bool $started = false;

    public bool $ended = false;

    /**
     * @var array{size: int, seed: int, cells: list<array<string, mixed>>, neighbors: list<list<int>>}
     */
    public array $board = [];

    public ?int $startedAt = null;

    public ?int $endsAt = null;

    /**
     * @var list<string>
     */
    public array $allValidWords = [];

    public function startGame(BoardGenerator $boardGenerator): void
    {
        $this->board = $boardGenerator->generate($this->size);
        $this->started = true;
        $this->ended = false;
        $this->allValidWords = [];
        $this->startedAt = now()->timestamp;
        $this->endsAt = ! $this->untimed && $this->minutes > 0
            ? now()->addMinutes($this->minutes)->timestamp
            : null;
    }

    public function tick(): void
    {
        if (! $this->ended && $this->endsAt !== null && now()->timestamp >= $this->endsAt) {
            $this->endGame();
        }
    }

    public function endGame(Solver $solver): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;
        $this->endsAt = null;
        $this->allValidWords = $solver->solve($this->board);
    }

    public function returnToSetup(): void
    {
        $this->started = false;
        $this->ended = false;
        $this->board = [];
        $this->allValidWords = [];
    }

    public function letterClass(string $display): string
    {
        $classes = ['letter'];

        if (in_array($display, self::WIDE, true)) {
            $classes[] = 'wide';
        }

        if (in_array($display, self::DESCENDER, true)) {
            $classes[] = 'descender';
        }

        return implode(' ', $classes);
    }

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

    public function getTimerPercentProperty(): ?float
    {
        if ($this->endsAt === null || $this->startedAt === null || $this->minutes <= 0) {
            return null;
        }

        $total = $this->minutes * 60;
        $remaining = max(0, $this->endsAt - now()->timestamp);

        return $total > 0 ? max(0, min(100, ($remaining / $total) * 100)) : 0;
    }

    public function getTimerLowProperty(): bool
    {
        if ($this->endsAt === null) {
            return false;
        }

        return max(0, $this->endsAt - now()->timestamp) <= 30;
    }
};
?>

<x-slot:title>Boggle (Group Play) — Low Stim Play</x-slot>
<x-slot:description>A shared-screen Boggle board for group play — big letters, an optional visual timer, and a full word reveal at the end. No typing required.</x-slot>
<x-slot:fullscreenToggle>1</x-slot:fullscreenToggle>

@if ($started)
    <x-slot:barRight>
        @if (! $ended)
            <button type="button" wire:click="endGame">End Game</button>
        @else
            <button type="button" wire:click="returnToSetup">New Game</button>
        @endif
    </x-slot:barRight>
@endif

@unless ($started)
    <div class="setup" id="setup">
        <div class="heading">Boggle (Group Play)</div>

        <div class="setup-row">
            <div class="label">Board Size</div>
            <div class="option-group">
                @foreach ($sizeOptions as $option)
                    <button type="button" class="option {{ $size === $option ? 'active' : '' }}" wire:click="$set('size', {{ $option }})">
                        {{ $option === 4 ? 'Classic 4x4' : 'Big 5x5' }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="setup-row">
            <div class="label">Time Limit</div>
            <div class="option-group">
                <button type="button" class="option {{ ! $untimed ? 'active' : '' }}" wire:click="$set('untimed', false)">Timed</button>
                <button type="button" class="option {{ $untimed ? 'active' : '' }}" wire:click="$set('untimed', true)">Untimed</button>
            </div>
        </div>

        <div class="setup-row {{ $untimed ? 'disabled' : '' }}">
            <div class="option-group">
                @foreach ($minuteOptions as $option)
                    <button type="button" class="option {{ $minutes === $option ? 'active' : '' }}" wire:click="$set('minutes', {{ $option }})">
                        {{ $option }} min
                    </button>
                @endforeach
            </div>
        </div>

        <div class="setup-row {{ $untimed ? 'disabled' : '' }}">
            <div class="label">Visual Timer</div>
            <div class="option-group">
                <button type="button" class="option {{ $visualTimerOn ? 'active' : '' }}" wire:click="$set('visualTimerOn', true)">Yes</button>
                <button type="button" class="option {{ ! $visualTimerOn ? 'active' : '' }}" wire:click="$set('visualTimerOn', false)">No</button>
            </div>
        </div>

        <button type="button" wire:click="startGame">Start Game</button>
    </div>
@else
    <div id="boggle-group-game" class="{{ $ended ? 'with-list' : '' }}">
        @if (! $ended && $endsAt !== null)
            <div wire:poll.1s="tick"></div>
        @endif

        @if (! $ended && $endsAt !== null && $visualTimerOn)
            <div class="timer-bar-wrap">
                <div class="timer-bar-fill {{ $this->timerLow ? 'low' : '' }}" style="width: {{ $this->timerPercent }}%"></div>
            </div>
        @endif

        <div class="board-area">
            <div class="grid" style="grid-template-columns: repeat({{ $size }}, minmax(0, 1fr));">
                @foreach ($board['cells'] as $cell)
                    <div class="die">
                        <span class="{{ $this->letterClass($cell['display']) }}">{{ $cell['display'] }}</span>
                    </div>
                @endforeach
            </div>

            @if ($ended)
                <div class="word-list-panel">
                    <div class="title">All Possible Words ({{ count($allValidWords) }})</div>
                    <div class="word-columns">
                        @foreach ($this->wordsByLength as $length => $words)
                            <div class="word-column">
                                <div class="word-column-header">{{ $length }} letters</div>
                                <ul class="word-list">
                                    @foreach ($words as $word)
                                        <li wire:key="word-{{ $word }}">{{ strtoupper($word) }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="status-line">&nbsp;</div>
    </div>
@endunless
