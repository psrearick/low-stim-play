<?php

use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Events\RoomEnded;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public Room $room;

    /**
     * Presence-channel membership (who's actually connected right now),
     * kept separate from the persistent $this->room->members below --
     * see docs/DEVLOG.md's Room system section for why this app already
     * draws that same distinction elsewhere (RoomSeat vs. presence).
     *
     * @var list<int>
     */
    public array $onlineUserIds = [];

    public function mount(string $code): void
    {
        $this->room = Room::notExpired()->where('code', strtoupper($code))->firstOrFail();

        // No auth middleware on this route -- see routes/web.php. Same
        // provisioning every other room entry point already uses.
        if (Auth::guest()) {
            Auth::login(User::createGuest());

            if (request()->hasSession()) {
                request()->session()->regenerate();
            }
        }

        $this->room->addMember(Auth::user());
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        $channel = "room.{$this->room->code}";

        return [
            "echo-presence:{$channel},here" => 'onHere',
            "echo-presence:{$channel},joining" => 'onJoining',
            "echo-presence:{$channel},leaving" => 'onLeaving',
            "echo-presence:{$channel},RoomEnded" => 'onRoomEnded',
        ];
    }

    /**
     * @param  array<int, array{id: int, name: string, is_host: bool}>  $members
     */
    public function onHere(array $members): void
    {
        $this->onlineUserIds = array_column($members, 'id');
    }

    /**
     * @param  array{id: int, name: string, is_host: bool}  $member
     */
    public function onJoining(array $member): void
    {
        $this->onlineUserIds = array_values(array_unique([...$this->onlineUserIds, $member['id']]));
    }

    /**
     * @param  array{id: int, name: string, is_host: bool}  $member
     */
    public function onLeaving(array $member): void
    {
        $this->onlineUserIds = array_values(array_diff($this->onlineUserIds, [$member['id']]));
    }

    public function onRoomEnded(): void
    {
        $this->redirect(url('/'));
    }

    /**
     * @return \Illuminate\Support\Collection<int, RoomMember>
     */
    public function getMembersProperty(): \Illuminate\Support\Collection
    {
        return $this->room->members()->with('user')->orderBy('created_at')->get();
    }

    public function getIsHostProperty(): bool
    {
        return Auth::id() === $this->room->host_user_id;
    }

    public function getMySeatIndexProperty(): ?int
    {
        return RoomSeat::where('room_id', $this->room->id)
            ->where('user_id', Auth::id())
            ->value('seat_index');
    }

    public function getActiveGameTypeProperty(): ?GameType
    {
        if ($this->room->status !== RoomStatus::Active || $this->room->game_session_id === null) {
            return null;
        }

        return $this->room->gameSession->type;
    }

    /**
     * @return list<GameType>
     */
    public function getStartableGamesProperty(): array
    {
        return GameType::cases();
    }

    public function endRoom(): void
    {
        abort_unless($this->isHost, 403);

        RoomEnded::dispatch($this->room);
        $this->room->delete();

        $this->redirect(url('/'));
    }

    public function leaveRoom(): void
    {
        RoomMember::where('room_id', $this->room->id)
            ->where('user_id', Auth::id())
            ->delete();

        $this->redirect(url('/'));
    }
};
?>

<x-slot:title>Room {{ $room->code }} — Low Stim Play</x-slot>

<div class="flex flex-col items-center gap-6 w-full max-w-lg">
    <div class="heading">Room</div>

    <div class="flex gap-2" style="justify-content: center;">
        <input type="text" readonly value="{{ $room->code }}" onclick="this.select()"
            style="width: 14ch; text-align: center; font-weight: bold; letter-spacing: 0.15em; font-size: clamp(20px, 2.6vmin, 30px); color: var(--bar);">
        <button type="button" data-copy="{{ $room->code }}">Copy</button>
    </div>

    @if ($this->activeGameType !== null)
        <div class="setup w-full">
            <p class="status-line">Playing {{ $this->activeGameType->label() }} right now.</p>
            <a href="{{ route($this->activeGameType->playRoute(), ['room' => $room->code]) }}">
                <button type="button">{{ $this->mySeatIndex !== null ? 'Resume your seat' : 'Watch' }}</button>
            </a>
        </div>
    @endif

    <div class="flex flex-col gap-2 w-full">
        <p class="text-sm text-text/80 font-bold">Members</p>
        <ul class="w-full flex flex-col gap-2">
            @forelse ($this->members as $member)
                <li wire:key="member-{{ $member->id }}" class="flex items-center justify-between w-full status-line">
                    <span>
                        {{ $member->user->name }}
                        @if ($member->user_id === $room->host_user_id)
                            <span style="opacity: 0.6;">(host)</span>
                        @endif
                    </span>
                    <span style="{{ in_array($member->user_id, $this->onlineUserIds, true) ? 'color: var(--accent);' : 'opacity: 0.5;' }}">
                        {{ in_array($member->user_id, $this->onlineUserIds, true) ? 'Online' : 'Offline' }}
                    </span>
                </li>
            @empty
                <li class="status-line">No members yet.</li>
            @endforelse
        </ul>
    </div>

    @if ($this->isHost)
        <div class="flex flex-col items-center gap-2 w-full">
            <p class="status-line">Start a new game in this room:</p>
            <div class="flex flex-wrap justify-center gap-2">
                @foreach ($this->startableGames as $type)
                    <a id="start-game-{{ $type->value }}" href="{{ route($type->roomRoute(), ['code' => $room->code]) }}" wire:key="start-{{ $type->value }}">
                        <button type="button">{{ $type->label() }}</button>
                    </a>
                @endforeach
            </div>
        </div>

        <button type="button" id="end-room-trigger" data-dialog-open="end-room-confirm-dialog">End Room</button>

        <x-confirm-dialog id="end-room-confirm-dialog" title="End this room?"
            message="This removes the room and every member from it. This can't be undone.">
            <x-slot:confirm>
                <button type="button" id="end-room-confirm" data-dialog-close wire:click="endRoom"
                    class="flex-1 rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-warn text-warn">
                    End Room
                </button>
            </x-slot:confirm>
        </x-confirm-dialog>
    @else
        <button type="button" id="leave-room-trigger" data-dialog-open="leave-room-confirm-dialog">Leave Room</button>

        <x-confirm-dialog id="leave-room-confirm-dialog" title="Leave this room?"
            message="You can rejoin later with the room code if it's still active.">
            <x-slot:confirm>
                <button type="button" id="leave-room-confirm" data-dialog-close wire:click="leaveRoom"
                    class="flex-1 rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-warn text-warn">
                    Leave Room
                </button>
            </x-slot:confirm>
        </x-confirm-dialog>
    @endif
</div>
