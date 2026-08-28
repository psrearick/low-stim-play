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
            // guest_name: an optional display name a repeat guest may have
            // typed once into <livewire:rooms-menu>'s own Join/Create
            // dialog (docs/concerns.md) -- null falls through to
            // createGuest()'s own "Guest" default exactly as before.
            Auth::login(User::createGuest(request()->cookie('guest_name')));

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
            "echo-presence:{$channel},RoomGameSwitched" => 'onGameSwitched',
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
     * The game the host is currently sitting on the lobby of, if any and
     * no game has actually started yet -- covers a member who loads this
     * page *after* the host already got here (whether via this hub's own
     * startGame() below or a game's own "play in a room you host"
     * shortcut), since RoomGameSwitched only ever reaches whoever's
     * viewing this page at the exact instant it's broadcast. See Room::
     * pendingGameType()'s own docblock.
     */
    public function getPendingGameTypeProperty(): ?GameType
    {
        return $this->room->pendingGameType();
    }

    /**
     * @return list<GameType>
     */
    public function getStartableGamesProperty(): array
    {
        return array_values(array_filter(
            GameType::cases(),
            fn (GameType $type) => $type->supportsRooms(),
        ));
    }

    /**
     * The host picking a game here is now the *only* doorway from the room
     * hub into that game's own lobby (pages::{game}.room) -- <livewire:
     * game-lobby> used to offer an identical "switch to a different game"
     * button of its own on every individual game's own setup page, removed
     * (docs/concerns.md: that choice belongs on the room hub, not
     * scattered across every game's own page) in favor of this being the
     * one place it happens. Resets game_session_id first for the same
     * reason a stale non-null id anywhere would make that game's own
     * onRoomStarting() skip creating a fresh session and silently reuse an
     * old, already-finished one.
     *
     * Deliberately does *not* mark the room pending or broadcast
     * RoomGameSwitched (docs/concerns.md) -- both moved to that game's own
     * openLobby() action instead, so nobody else is notified/able to join
     * until the host actually confirms config there, not the instant they
     * merely pick a game type here.
     */
    public function startGame(GameType $type): void
    {
        abort_unless($this->isHost, 403);

        $this->room->update(['game_session_id' => null]);

        $this->redirect(route($type->roomRoute(), ['code' => $this->room->code]));
    }

    /**
     * No longer redirects -- other members used to be yanked straight into
     * the newly-picked game the instant the host chose it, which is the
     * "timing is weird" complaint in docs/concerns.md (getting teleported
     * mid-thought, before ever choosing to follow). Now this just re-renders
     * (any Livewire listener invocation does), which recomputes
     * $this->pendingGameType from the cache startGame() above just wrote and
     * shows the existing "host is setting up X -- Join" banner below instead
     * -- the same banner a late joiner already sees. Mirrors
     * <livewire:game-lobby>'s own onGameSwitched().
     */
    public function onGameSwitched(): void {}

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
            @if ($this->mySeatIndex !== null)
                <a href="{{ route($this->activeGameType->playRoute(), ['room' => $room->code]) }}">
                    <button type="button">Resume your seat</button>
                </a>
            @elseif ($this->activeGameType->supportsSpectating())
                <a href="{{ route($this->activeGameType->playRoute(), ['room' => $room->code]) }}">
                    <button type="button">Watch</button>
                </a>
            @else
                {{-- No RoomSeat/spectator concept for this game (Boggle) --
                     joining mid-game would actually start a fresh
                     playthrough, not watch the one in progress, so no link
                     is offered at all (see GameType::supportsSpectating()'s
                     own docblock). --}}
                <p class="status-line">No spectating for {{ $this->activeGameType->label() }} -- check back once this game finishes to join the next one.</p>
            @endif
        </div>
    @elseif ($this->pendingGameType !== null)
        <div class="setup w-full">
            <p class="status-line">The host is setting up {{ $this->pendingGameType->label() }} -- hasn't started yet.</p>
            <a href="{{ route($this->pendingGameType->roomRoute(), ['code' => $room->code]) }}">
                <button type="button">Join</button>
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
                    <button type="button" id="start-game-{{ $type->value }}" wire:click="startGame('{{ $type->value }}')" wire:key="start-{{ $type->value }}">
                        {{ $type->label() }}
                    </button>
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
