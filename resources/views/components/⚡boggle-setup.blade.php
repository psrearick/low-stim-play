<?php

use Livewire\Component;

new class extends Component
{
    public int $size = 4;

    public int $minutes = 3;

    /**
     * @var list<int>
     */
    public array $sizeOptions = [4, 5];

    /**
     * @var list<int>
     */
    public array $minuteOptions = [1, 2, 3, 5];

    public function startGame(): void
    {
        $this->redirect(route('boggle.play', ['size' => $this->size, 'minutes' => $this->minutes]));
    }
};
?>

<div class="flex flex-col items-center gap-5 w-full max-w-md bg-surface border-2 border-surface-border rounded-lg p-8">
    <div class="flex flex-col items-center gap-2 w-full">
        <p class="text-sm text-text/80">Board size</p>
        <div class="flex gap-2">
            @foreach ($sizeOptions as $option)
                <button type="button" wire:click="$set('size', {{ $option }})"
                    class="rounded px-6 py-2 font-bold tracking-wide border-2 {{ $size === $option ? 'bg-accent text-bg border-accent' : 'bg-surface text-text border-surface-border' }}">
                    {{ $option }}&times;{{ $option }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="flex flex-col items-center gap-2 w-full">
        <p class="text-sm text-text/80">Time limit</p>
        <div class="flex flex-wrap justify-center gap-2">
            @foreach ($minuteOptions as $option)
                <button type="button" wire:click="$set('minutes', {{ $option }})"
                    class="rounded px-4 py-2 font-bold tracking-wide border-2 {{ $minutes === $option ? 'bg-accent text-bg border-accent' : 'bg-surface text-text border-surface-border' }}">
                    {{ $option }} min
                </button>
            @endforeach
            <button type="button" wire:click="$set('minutes', 0)"
                class="rounded px-4 py-2 font-bold tracking-wide border-2 {{ $minutes === 0 ? 'bg-accent text-bg border-accent' : 'bg-surface text-text border-surface-border' }}">
                Untimed
            </button>
        </div>
    </div>

    <button type="button" wire:click="startGame"
        class="w-full rounded px-6 py-2 font-bold tracking-wide bg-accent text-bg hover:opacity-90">
        Start solo game
    </button>
</div>
