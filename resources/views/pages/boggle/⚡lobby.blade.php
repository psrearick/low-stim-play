<?php

use App\Models\Room;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    /**
     * @return \Illuminate\Support\Collection<int, Room>
     */
    public function getHostedRoomsProperty(): \Illuminate\Support\Collection
    {
        if (Auth::guest()) {
            return collect();
        }

        return Room::where('host_user_id', Auth::id())->notExpired()->get();
    }
};
?>

<x-slot:title>Word Grid — Low Stim Play</x-slot>
<x-slot:description>Play Word Grid online — a shuffled 4x4 or 5x5 letter grid, timed or untimed, with calm low-contrast visuals and no flashing or motion.</x-slot>

<div class="flex flex-col items-center" style="gap: inherit;">
    <div class="heading">Word Grid</div>

    <livewire:boggle-setup />

    @if ($this->hostedRooms->isNotEmpty())
        <div class="flex flex-col items-center gap-2 w-full">
            <p class="status-line">Play in a room you host:</p>
            <div class="flex flex-wrap justify-center gap-2">
                @foreach ($this->hostedRooms as $hostedRoom)
                    <a href="{{ route('boggle.room', ['code' => $hostedRoom->code]) }}" wire:key="hosted-room-{{ $hostedRoom->id }}">
                        <button type="button" id="hosted-room-{{ $hostedRoom->code }}">{{ $hostedRoom->code }}</button>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</div>
