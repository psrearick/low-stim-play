<?php

use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Events\RoomPlayerReadyToggled;
use App\Models\Room;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

new class extends Component
{
    public Room $room;

    /**
     * Which game's own room page currently hosts this component -- used by
     * getSwitchedGameTypeProperty() below to tell "the host switched to a
     * *different* game" apart from "this game's own GameSession changed."
     * Every {game}.room page passes its own literal GameType in.
     */
    public GameType $currentGame;

    /**
     * Currently present players, keyed by user id.
     *
     * @var array<int, array{id: int, name: string, is_host: bool, ready: bool}>
     */
    public array $players = [];

    public function mount(Room $room, GameType $currentGame): void
    {
        $this->room = $room;
        $this->currentGame = $currentGame;
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
            "echo-presence:{$channel},RoomPlayerReadyToggled" => 'onReadyToggled',
            "echo-presence:{$channel},RoomGameStarting" => 'onGameStarting',
            "echo-presence:{$channel},RoomGameSwitched" => 'onGameSwitched',
        ];
    }

    /**
     * @param  array<int, array{id: int, name: string, is_host: bool}>  $members
     */
    public function onHere(array $members): void
    {
        foreach ($members as $member) {
            $this->players[$member['id']] = [...$member, 'ready' => $this->cachedReady($member['id'])];
        }
    }

    /**
     * @param  array{id: int, name: string, is_host: bool}  $member
     */
    public function onJoining(array $member): void
    {
        $this->players[$member['id']] = [...$member, 'ready' => $this->cachedReady($member['id'])];
    }

    /**
     * @param  array{id: int, name: string, is_host: bool}  $member
     */
    public function onLeaving(array $member): void
    {
        unset($this->players[$member['id']]);
    }

    /**
     * @param  array{user_id: int, ready: bool}  $event
     */
    public function onReadyToggled(array $event): void
    {
        if (isset($this->players[$event['user_id']])) {
            $this->players[$event['user_id']]['ready'] = $event['ready'];
        }
    }

    public function onGameStarting(): void
    {
        $this->room->refresh();
    }

    public function toggleReady(): void
    {
        $userId = Auth::id();
        $ready = ! $this->myReady;

        if (isset($this->players[$userId])) {
            $this->players[$userId]['ready'] = $ready;
        }

        // Persisted, not just broadcast -- RoomPlayerReadyToggled only ever
        // reaches whoever's actively connected to the presence channel at
        // the instant it fires. A reconnecting client's onHere()/onJoining()
        // (above) has no way to recover another member's current ready
        // status from the presence payload alone (it only ever carries
        // id/name/is_host, see routes/channels.php), so without this,
        // everyone re-appears "not ready" to a client that just reconnected
        // -- even players who never actually un-readied.
        Cache::put($this->readyCacheKey($userId), $ready, now()->addHours(3));

        RoomPlayerReadyToggled::dispatch($this->room, $userId, $ready);
    }

    private function cachedReady(int $userId): bool
    {
        return Cache::get($this->readyCacheKey($userId), false);
    }

    private function readyCacheKey(int $userId): string
    {
        return "room:{$this->room->code}:ready:{$userId}";
    }

    public function startGame(): void
    {
        abort_unless(Auth::id() === $this->room->host_user_id, 403);
        abort_unless($this->allReady, 403);

        // game_session_id is reset here, not just set once for the room's
        // whole lifetime -- this room may be replayed (same code, "Back to
        // room" after a prior game), and pages::boggle.room::onRoomStarting()
        // only generates a fresh board/session when it finds this null.
        // Leaving a prior game's id in place would make it silently reuse
        // that old (already-ended) session/board for the host, and skip
        // broadcasting RoomGameStarting entirely (that dispatch only
        // happens inside the same "it's null" branch), stranding the guest
        // in the lobby.
        $this->room->update(['status' => RoomStatus::Active, 'game_session_id' => null]);

        // Clear every present player's cached ready flag now that it's
        // actually been consumed -- otherwise a replayed room ("Back to
        // room" after a finished game) would have every returning player's
        // onHere()/onJoining() immediately read back "ready" from the
        // *previous* round, enabling Start Game again before anyone
        // actually re-confirms for the new one.
        foreach (array_keys($this->players) as $userId) {
            Cache::forget($this->readyCacheKey($userId));
        }

        // A plain (non-broadcast) Livewire event, handled synchronously by
        // whichever game-specific page hosts this component -- letting each
        // game (Boggle, Yahtzee, ...) decide what "starting" means for it
        // (e.g. generating a shared board) without this component knowing
        // anything game-specific. That handler is also responsible for
        // broadcasting RoomGameStarting itself, once it's actually done
        // preparing the game -- broadcasting it from here, before that
        // preparation has even happened, raced other players' "is it ready
        // yet?" check against this component's own separate follow-up
        // request and could lose.
        //
        // $this->players is passed along too, for games (Yahtzee's online
        // room) that need to assign a seat per present player at start
        // time -- Livewire binds dispatched payload args to listener
        // params by name, so Boggle's own onRoomStarting(BoardGenerator
        // $boardGenerator), which never declares a $players param, simply
        // never receives it and keeps working unmodified.
        $this->dispatch('room-game-starting', players: $this->players);
    }

    /**
     * No longer redirects -- see pages::room.show::onGameSwitched()'s
     * matching comment. Just re-renders, so getSwitchedGameTypeProperty()
     * below picks up whatever pages::room.show's own startGame() (the only
     * place that switches a room's game now -- see docs/concerns.md and
     * this file's own removed switchGame()/otherGames below) most recently
     * wrote.
     */
    public function onGameSwitched(): void {}

    /**
     * The game the host just switched *this room* to via pages::room.show
     * (docs/concerns.md), if any -- null once that game actually starts (a
     * real GameSession exists) or if nothing's been switched to since this
     * lobby's own game last started. Always a *different* game than
     * $currentGame, since pages::room.show::startGame() is never offered
     * for the room's own currently-active game.
     */
    public function getSwitchedGameTypeProperty(): ?GameType
    {
        $type = $this->room->pendingGameType();

        return $type !== $this->currentGame ? $type : null;
    }

    public function getAllReadyProperty(): bool
    {
        return count($this->players) > 0
            && collect($this->players)->every(fn (array $player) => $player['ready']);
    }

    public function getIsHostProperty(): bool
    {
        return Auth::id() === $this->room->host_user_id;
    }

    public function getMyReadyProperty(): bool
    {
        return $this->players[Auth::id()]['ready'] ?? false;
    }
};
?>

<div class="setup">
    <div class="flex gap-2" style="justify-content: center;">
        <input type="text" readonly value="{{ $room->code }}" onclick="this.select()"
            style="width: 14ch; text-align: center; font-weight: bold; letter-spacing: 0.15em; font-size: clamp(20px, 2.6vmin, 30px); color: var(--bar);">
        <button type="button" data-copy="{{ $room->code }}">Copy</button>
    </div>
    <p class="status-line">Share this code so others can join.</p>

    @if ($this->switchedGameType !== null)
        <div class="setup w-full">
            <p class="status-line">The host switched to {{ $this->switchedGameType->label() }}.</p>
            <a href="{{ route($this->switchedGameType->roomRoute(), ['code' => $room->code]) }}">
                <button type="button">Join</button>
            </a>
        </div>
    @endif

    <ul class="w-full flex flex-col gap-2">
        @forelse ($players as $player)
            <li wire:key="player-{{ $player['id'] }}" class="flex items-center justify-between w-full status-line">
                <span>
                    {{ $player['name'] }}
                    @if ($player['is_host'])
                        <span style="opacity: 0.6;">(host)</span>
                    @endif
                </span>
                <span style="{{ $player['ready'] ? 'color: var(--accent);' : '' }}">
                    {{ $player['ready'] ? 'Ready' : 'Not ready' }}
                </span>
            </li>
        @empty
            <li class="status-line">Waiting for players...</li>
        @endforelse
    </ul>

    <button type="button" wire:click="toggleReady">
        {{ $this->myReady ? "I'm not ready" : "I'm ready" }}
    </button>

    @if ($this->isHost)
        <button type="button" wire:click="startGame" @disabled(! $this->allReady)>
            Start Game
        </button>
    @endif
</div>
