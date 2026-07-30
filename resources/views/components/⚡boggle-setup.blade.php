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

<div class="setup">
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
            @foreach ($minuteOptions as $option)
                <button type="button" class="option {{ $minutes === $option ? 'active' : '' }}" wire:click="$set('minutes', {{ $option }})">
                    {{ $option }} min
                </button>
            @endforeach
            <button type="button" class="option {{ $minutes === 0 ? 'active' : '' }}" wire:click="$set('minutes', 0)">
                Untimed
            </button>
        </div>
    </div>

    <button type="button" wire:click="startGame">Start Solo Game</button>
</div>
