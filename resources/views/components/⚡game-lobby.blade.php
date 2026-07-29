<?php

use App\Enums\RoomStatus;
use App\Events\RoomGameStarting;
use App\Events\RoomPlayerReadyToggled;
use App\Models\Room;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public Room $room;

    /**
     * Currently present players, keyed by user id.
     *
     * @var array<int, array{id: int, name: string, is_host: bool, ready: bool}>
     */
    public array $players = [];

    public function mount(Room $room): void
    {
        $this->room = $room;
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
        ];
    }

    /**
     * @param  array<int, array{id: int, name: string, is_host: bool}>  $members
     */
    public function onHere(array $members): void
    {
        foreach ($members as $member) {
            $this->players[$member['id']] = [...$member, 'ready' => false];
        }
    }

    /**
     * @param  array{id: int, name: string, is_host: bool}  $member
     */
    public function onJoining(array $member): void
    {
        $this->players[$member['id']] = [...$member, 'ready' => false];
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

        RoomPlayerReadyToggled::dispatch($this->room, $userId, $ready);
    }

    public function startGame(): void
    {
        abort_unless(Auth::id() === $this->room->host_user_id, 403);
        abort_unless($this->allReady, 403);

        $this->room->update(['status' => RoomStatus::Active]);

        RoomGameStarting::dispatch($this->room);
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

<div class="flex flex-col items-center gap-5 w-full max-w-md bg-surface border-2 border-surface-border rounded-lg p-8">
    <p class="text-accent font-bold tracking-widest text-3xl">{{ $room->code }}</p>
    <p class="text-sm text-text/65">Share this code so others can join.</p>

    <ul class="w-full flex flex-col gap-2">
        @forelse ($players as $player)
            <li wire:key="player-{{ $player['id'] }}" class="flex items-center justify-between text-sm text-text/80">
                <span>
                    {{ $player['name'] }}
                    @if ($player['is_host'])
                        <span class="text-text/50">(host)</span>
                    @endif
                </span>
                <span class="{{ $player['ready'] ? 'text-accent' : 'text-text/50' }}">
                    {{ $player['ready'] ? 'Ready' : 'Not ready' }}
                </span>
            </li>
        @empty
            <li class="text-sm text-text/65 text-center">Waiting for players...</li>
        @endforelse
    </ul>

    <button type="button" wire:click="toggleReady" class="w-full rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-surface-border text-text">
        {{ $this->myReady ? "I'm not ready" : "I'm ready" }}
    </button>

    @if ($this->isHost)
        <button type="button" wire:click="startGame" @disabled(! $this->allReady)
            class="w-full rounded px-6 py-2 font-bold tracking-wide bg-accent text-bg disabled:opacity-40">
            Start game
        </button>
    @endif
</div>
