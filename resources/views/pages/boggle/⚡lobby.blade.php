<?php

use App\Models\Room;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public string $joinCode = '';

    public ?string $joinError = null;

    public function createRoom(): void
    {
        $room = Room::create(['host_user_id' => Auth::id()]);

        $this->redirect(route('boggle.room', ['code' => $room->code]));
    }

    public function joinRoom(): void
    {
        $code = strtoupper(trim($this->joinCode));

        if (! Room::notExpired()->where('code', $code)->exists()) {
            $this->joinError = 'No active room with that code.';

            return;
        }

        $this->redirect(route('boggle.room', ['code' => $code]));
    }
};
?>

<x-slot:title>Boggle — Low Stim Play</x-slot>
<x-slot:description>Play Boggle online — a shuffled 4x4 or 5x5 letter grid, timed or untimed, with calm low-contrast visuals and no flashing or motion.</x-slot>

<div class="flex flex-col items-center" style="gap: inherit;">
    <div class="heading">Boggle</div>

    <livewire:boggle-setup />

    @auth
        <div class="setup">
            <button type="button" wire:click="createRoom">Host an Online Room</button>

            <div class="status-line">or</div>

            <form wire:submit="joinRoom" class="flex gap-2 w-full">
                <input type="text" wire:model="joinCode" maxlength="6" placeholder="Room code" style="flex: 1; text-transform: uppercase;">
                <button type="submit">Join</button>
            </form>

            @if ($joinError)
                <p class="status-line" style="color: var(--warn);">{{ $joinError }}</p>
            @endif
        </div>
    @else
        <p class="status-line"><a href="{{ route('login') }}" style="color: var(--bar);">Sign in</a> to host or join an online room.</p>
    @endauth
</div>
