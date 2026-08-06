<?php

use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The one navbar entry point for everything room-related (docs/concerns.md
 * #8/#9): a list of rooms the current account belongs to, a "Join a Room"
 * dialog, and a "Create a Room" action -- available from every page (the
 * homepage included) since this lives in layouts/app.blade.php, not any
 * one game's own setup screen.
 */
new class extends Component
{
    public string $joinCode = '';

    public ?string $joinError = null;

    /**
     * @return \Illuminate\Support\Collection<int, RoomMember>
     */
    public function getMyRoomsProperty(): \Illuminate\Support\Collection
    {
        if (Auth::guest()) {
            return collect();
        }

        return RoomMember::where('user_id', Auth::id())
            ->whereHas('room', fn ($query) => $query->notExpired())
            ->with('room')
            ->get();
    }

    public function joinByCode(): void
    {
        $code = strtoupper(trim($this->joinCode));
        $room = Room::notExpired()->where('code', $code)->first();

        if (! $room) {
            $this->joinError = 'No active room with that code.';

            return;
        }

        if (Auth::guest()) {
            Auth::login(User::createGuest());

            if (request()->hasSession()) {
                request()->session()->regenerate();
            }
        }

        $room->addMember(Auth::user());

        $this->redirect(route('room.show', ['code' => $room->code]));
    }

    public function createRoom(): void
    {
        if (Auth::guest()) {
            Auth::login(User::createGuest());

            if (request()->hasSession()) {
                request()->session()->regenerate();
            }
        }

        $room = Room::create(['host_user_id' => Auth::id()]);

        $this->redirect(route('room.show', ['code' => $room->code]));
    }
};
?>

<div class="relative inline-flex">
    <button type="button" id="rooms-menu-toggle" wire:ignore.self aria-haspopup="true" aria-expanded="false">
        Rooms <span aria-hidden="true">&#9662;</span>
    </button>

    <div id="rooms-menu-panel" wire:ignore.self hidden
        class="absolute right-0 top-full mt-2 w-64 rounded-lg border-2 border-surface-border bg-surface p-3 flex flex-col gap-2 z-10">
        <p class="text-xs text-text/65 font-bold px-1">Your rooms</p>

        <ul class="flex flex-col gap-1">
            @forelse ($this->myRooms as $member)
                <li wire:key="rooms-menu-{{ $member->room->id }}">
                    <a href="{{ route('room.show', ['code' => $member->room->code]) }}"
                        class="flex items-center justify-between px-2 py-2 rounded text-sm text-text hover:bg-bg/60">
                        <span>{{ $member->room->code }}</span>
                        <span class="text-xs text-text/65">{{ ucfirst($member->room->status->value) }}</span>
                    </a>
                </li>
            @empty
                <li class="px-2 py-1 text-sm text-text/65">You're not in any rooms.</li>
            @endforelse
        </ul>

        <div class="border-t border-surface-border my-1"></div>

        <button type="button" id="rooms-menu-join-trigger" data-dialog-open="join-room-dialog"
            class="text-left px-2 py-2 rounded text-sm text-text hover:bg-bg/60">
            Join a Room
        </button>

        <button type="button" id="rooms-menu-create" wire:click="createRoom"
            class="text-left px-2 py-2 rounded text-sm text-text hover:bg-bg/60">
            Create a Room
        </button>
    </div>

    {{--
        wire:ignore.self -- without it, a failed joinByCode() (invalid
        code) re-renders this component to show $joinError, which morphs
        this <dialog>'s own open attribute back to absent (the server-
        rendered state, which knows nothing about the showModal() call
        that opened it) and silently closes it out from under the user
        before they ever see the error. Same bug class as the Yahtzee
        scoreboard-drawer's dialog[open] fix documented in
        docs/DEVLOG.md -- .self still lets $joinError's own text morph
        normally inside, only the <dialog> element's own open/closed
        state is left untouched.
    --}}
    <x-dialog id="join-room-dialog" title="Join a Room" wire:ignore.self>
        <form wire:submit="joinByCode" class="flex flex-col gap-3 w-full">
            <input type="text" id="join-room-code" wire:model="joinCode" placeholder="Room ID" autocomplete="off"
                style="text-transform: uppercase;">

            @if ($joinError)
                <p class="status-line" style="color: var(--warn);">{{ $joinError }}</p>
            @endif

            <div class="flex gap-3 w-full">
                <button type="button" data-dialog-close
                    class="flex-1 rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-surface-border text-text">
                    Cancel
                </button>
                <button type="submit" id="join-room-submit"
                    class="flex-1 rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-accent text-accent">
                    Join
                </button>
            </div>
        </form>
    </x-dialog>
</div>
