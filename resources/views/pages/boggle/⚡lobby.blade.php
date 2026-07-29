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

<div class="flex flex-col items-center gap-6 w-full">
    <h1 class="text-accent font-bold tracking-wide text-3xl">Boggle</h1>

    <livewire:boggle-setup />

    @auth
        <div class="flex flex-col items-center gap-4 w-full max-w-md bg-surface border-2 border-surface-border rounded-lg p-8">
            <button type="button" wire:click="createRoom" class="w-full rounded px-6 py-2 font-bold tracking-wide bg-accent text-bg hover:opacity-90">
                Host an online room
            </button>

            <div class="flex items-center gap-3 w-full text-text/50 text-xs uppercase tracking-widest">
                <span class="h-px flex-1 bg-surface-border"></span>
                or
                <span class="h-px flex-1 bg-surface-border"></span>
            </div>

            <form wire:submit="joinRoom" class="w-full flex gap-2">
                <input type="text" wire:model="joinCode" maxlength="6" placeholder="Room code"
                    class="flex-1 rounded border-2 border-surface-border bg-bg text-text px-3 py-2 uppercase tracking-widest">
                <button type="submit" class="rounded px-4 py-2 font-bold bg-surface border-2 border-surface-border text-text">
                    Join
                </button>
            </form>

            @if ($joinError)
                <p class="text-sm text-warn">{{ $joinError }}</p>
            @endif
        </div>
    @else
        <p class="text-sm text-text/65">
            <a href="{{ route('login') }}" class="underline hover:text-text">Sign in</a> to host or join an online room.
        </p>
    @endauth
</div>
