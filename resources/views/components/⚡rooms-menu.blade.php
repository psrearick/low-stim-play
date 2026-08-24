<?php

use App\Enums\GameType;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * The one entry point for everything room-related (docs/concerns.md
 * #8/#9): a list of rooms the current account belongs to, a "Join a Room"
 * dialog, and a "Create a Room" action -- available from every page (the
 * homepage included) since this is rendered inside the site menu drawer
 * (<x-app-sidebar />, itself rendered once in layouts/app.blade.php), not
 * any one game's own setup screen. Folded into the drawer rather than
 * kept as its own persistent bar dropdown (docs/concerns.md) -- one menu
 * to open instead of two.
 */
new class extends Component
{
    public string $joinCode = '';

    public ?string $joinError = null;

    /**
     * Optional display name for a not-yet-logged-in visitor (docs/
     * concerns.md) -- shown only @guest, above the Join/Create controls.
     * Threaded into User::createGuest() by both actions below instead of
     * the "Guest" default. Also remembered in a long-lived cookie (see
     * rememberGuestName()) so a repeat guest who typed a name once here
     * gets it picked up automatically on a later direct room-URL visit
     * too, where there's no dialog to ask again -- see each game's own
     * {game}.room mount(), which reads that cookie.
     */
    public string $guestName = '';

    /**
     * Room-switch/start pings for rooms this account belongs to, surfaced
     * here rather than only on the room hub or a game's own lobby (docs/
     * concerns.md #1) so a member who's elsewhere in the app entirely --
     * mid-play in a *different* game, browsing another page -- still finds
     * out the host moved on, instead of only discovering it whenever they
     * next happen to click "Back to room" themselves.
     *
     * @var list<array{id: string, message: string, href: string}>
     */
    public array $notifications = [];

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

    /**
     * One pair of echo-presence listeners per room this account belongs to
     * -- this component is mounted on every page (layouts/app.blade.php),
     * not just the room hub or a game's own lobby, so it's the one place
     * that can notify a member regardless of what page they're actually on.
     * Bounded by $this->myRooms itself (notExpired(), so this can't grow
     * without limit as old rooms accumulate).
     *
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        $listeners = [];

        foreach ($this->myRooms as $member) {
            $channel = "room.{$member->room->code}";
            $listeners["echo-presence:{$channel},RoomGameSwitched"] = 'onGameSwitched';
            $listeners["echo-presence:{$channel},RoomGameStarting"] = 'onGameStarting';
        }

        return $listeners;
    }

    /**
     * @param  array{type: string, code: string}  $event
     */
    public function onGameSwitched(array $event): void
    {
        $room = $this->findRoomByCode($event['code'] ?? null);

        if ($room === null) {
            return;
        }

        $type = GameType::from($event['type']);

        // Straight to the game being set up (docs/concerns.md), not the
        // room hub -- roomRoute() is the same {game}.room lobby page a
        // member would otherwise have to detour through room.show to
        // reach, so this saves the "click Join, then click the game again"
        // round trip the old room.show link required.
        $this->pushNotification(
            "Host is setting up {$type->label()} in Room {$room->code}.",
            route($type->roomRoute(), ['code' => $room->code]),
        );
    }

    /**
     * @param  array{code: string}  $event
     */
    public function onGameStarting(array $event): void
    {
        $room = $this->findRoomByCode($event['code'] ?? null);

        if ($room === null) {
            return;
        }

        // Same reasoning as onGameSwitched() above -- straight into the
        // game itself rather than the room hub. $room->gameSession is
        // already set by the time RoomGameStarting broadcasts (every
        // {game}.room's own onRoomStarting() persists it before
        // dispatching), so the null fallback below is only defensive.
        $type = $room->gameSession?->type;

        $href = $type !== null
            ? route($type->playRoute(), ['room' => $room->code])
            : route('room.show', ['code' => $room->code]);

        $this->pushNotification("The game has started in Room {$room->code}.", $href);
    }

    public function dismissNotification(string $id): void
    {
        $this->notifications = array_values(array_filter(
            $this->notifications,
            fn (array $notification) => $notification['id'] !== $id,
        ));
    }

    private function findRoomByCode(?string $code): ?Room
    {
        if ($code === null) {
            return null;
        }

        return $this->myRooms->first(fn (RoomMember $member) => $member->room->code === $code)?->room;
    }

    private function pushNotification(string $message, string $href): void
    {
        $this->notifications[] = ['id' => (string) Str::uuid(), 'message' => $message, 'href' => $href];
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
            Auth::login(User::createGuest($this->guestName));
            $this->rememberGuestName();

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
            Auth::login(User::createGuest($this->guestName));
            $this->rememberGuestName();

            if (request()->hasSession()) {
                request()->session()->regenerate();
            }
        }

        $room = Room::create(['host_user_id' => Auth::id()]);

        $this->redirect(route('room.show', ['code' => $room->code]));
    }

    /**
     * Queues a long-lived cookie so a repeat guest who typed a name here
     * once gets it picked up automatically next time, including on a
     * direct room-URL visit that never goes through this dialog at all --
     * see each game's own {game}.room mount(). Only queued when a name was
     * actually given, so an empty field never overwrites a previously
     * remembered one with blank.
     */
    private function rememberGuestName(): void
    {
        $name = trim($this->guestName);

        if ($name !== '') {
            Cookie::queue('guest_name', $name, 60 * 24 * 365);
        }
    }
};
?>

<div>
    {{--
        Teleported to layouts/app.blade.php's #room-notifications-anchor
        (docs/concerns.md), a body-level target outside <x-app-sidebar />
        entirely -- .app-sidebar is display: none while the drawer is
        closed, which un-renders this whole block (position: fixed and
        all) regardless of the drawer's own open state, so left in place
        it only ever showed up while the drawer happened to be open.

        Wrapped in a `display: contents` div, same as every other
        @teleport in this app (see e.g. layouts/app.blade.php's own
        #game-actions docblock) -- Alpine's x-teleport (what @teleport
        compiles to) needs one stable root element to move regardless of
        which branch renders, and without this wrapper there'd be zero
        root elements at all whenever $notifications is empty.
    --}}
    @teleport('#room-notifications-anchor')
        <div style="display: contents;">
            @if (count($notifications) > 0)
                <div id="room-notifications" class="fixed top-16 right-3 z-50 flex flex-col gap-2 w-72 max-w-[90vw]">
                    @foreach ($notifications as $notification)
                        <div wire:key="rooms-menu-notification-{{ $notification['id'] }}"
                            class="rounded-lg border-2 border-accent bg-surface p-3 flex flex-col gap-2">
                            <p class="status-line">{{ $notification['message'] }}</p>
                            <div class="flex gap-2">
                                <a href="{{ $notification['href'] }}" class="flex-1">
                                    <button type="button" class="w-full">Join</button>
                                </a>
                                <button type="button" wire:click="dismissNotification('{{ $notification['id'] }}')">Dismiss</button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endteleport

    <p class="text-xs text-text/65 font-bold px-1">Your rooms</p>

    <ul class="flex flex-col gap-1">
        @forelse ($this->myRooms as $member)
            <li wire:key="rooms-menu-{{ $member->room->id }}">
                <a href="{{ route('room.show', ['code' => $member->room->code]) }}"
                    class="app-sidebar-link flex items-center justify-between gap-2">
                    <span>{{ $member->room->code }}</span>
                    <span class="flex items-center gap-1 text-xs text-text/65">
                        {{ ucfirst($member->room->status->value) }}
                        <span aria-hidden="true">&rsaquo;</span>
                    </span>
                </a>
            </li>
        @empty
            <li class="px-2 py-1 text-sm text-text/65">You're not in any rooms.</li>
        @endforelse
    </ul>

    <div class="flex flex-col gap-2">
        @guest
            {{--
                Optional -- an empty value falls through to
                User::createGuest()'s own "Guest" default exactly as
                before. Shared by both Join and Create below rather than
                two separate fields in two separate dialogs, since it's
                the same "who am I" question either way. w-full so it
                lines up with the full-width buttons below it instead of
                sitting narrower than them (docs/concerns.md) -- at its
                browser-default size it rendered noticeably narrower than
                "Join a Room"/"Create a Room", and with zero gap between
                them the input's bottom border and the button's top
                border sat flush against each other, reading as a slight
                overlap rather than two distinct controls.
            --}}
            <input type="text" id="rooms-menu-guest-name" wire:model="guestName" placeholder="Your name (optional)" maxlength="30" class="text-sm w-full">
        @endguest

        <button type="button" id="rooms-menu-join-trigger" data-dialog-open="join-room-dialog" class="app-sidebar-link">
            Join a Room
        </button>

        <button type="button" id="rooms-menu-create" wire:click="createRoom" class="app-sidebar-link">
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
