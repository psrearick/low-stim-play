<?php

use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Models\Room;
use Livewire\Component;

/**
 * Shared across every room game's own play page (mirrors <livewire:
 * game-lobby>'s "one game-agnostic component, every game drops it in"
 * shape) -- tells a visitor still sitting on *this* page's own game
 * (finished or not) that the room has moved on since this page loaded,
 * covering both the moment the host has *started* a new/different session
 * and the moment the host is merely *setting one up* (docs/concerns.md):
 * a player parked on an old end-game screen previously saw nothing at all
 * until the host finished gathering players and clicked Start on the new
 * game's own lobby, which forced everyone to leave the old page first just
 * to have anywhere to click through. Mirrors pages::room.show's own
 * activeGameType/pendingGameType split (see that page's own docblocks) so
 * the message/link here matches what a room-hub visitor already sees.
 */
new class extends Component
{
    public ?Room $room = null;

    public GameType $currentGame;

    public ?int $myGameSessionId = null;

    /**
     * The signature (see getNoticeProperty()) of the notice a player last
     * dismissed, so it stays hidden across re-renders of this same
     * component instance. Left as-is (not cleared) on every RoomGameSwitched/
     * RoomGameStarting broadcast -- a genuinely new target always carries a
     * different signature and so surfaces again on its own; re-showing the
     * exact same dismissed target on a redundant broadcast would be noise.
     */
    public ?string $dismissedSignature = null;

    public function mount(string $roomCode, GameType $currentGame, ?int $myGameSessionId = null): void
    {
        // Nullable, not firstOrFail() -- this is a purely informational
        // widget dropped onto an already-working page; a room that's
        // somehow gone by the time this mounts should just render nothing,
        // not take the whole page down with it.
        $this->room = Room::where('code', $roomCode)->first();
        $this->currentGame = $currentGame;
        $this->myGameSessionId = $myGameSessionId;
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        if ($this->room === null) {
            return [];
        }

        $channel = "room.{$this->room->code}";

        return [
            "echo-presence:{$channel},RoomGameStarting" => 'onRoomChanged',
            "echo-presence:{$channel},RoomGameSwitched" => 'onRoomChanged',
        ];
    }

    public function onRoomChanged(): void
    {
        $this->room?->refresh();
    }

    /**
     * The banner to show, if any -- either a real game session the room has
     * moved on to (started() branch) or the host merely setting one up
     * (pending() branch, Room::pendingGameType()'s own docblock covers why
     * this needs its own cache-backed source rather than only game_session_id).
     * Null the entire time nothing's changed, or once the player has
     * dismissed this exact target.
     *
     * @return array{signature: string, message: string, href: ?string, buttonLabel: ?string}|null
     */
    public function getNoticeProperty(): ?array
    {
        $notice = $this->currentTarget();

        if ($notice === null || $notice['signature'] === $this->dismissedSignature) {
            return null;
        }

        return $notice;
    }

    /**
     * The raw target this room has moved on to, ignoring dismissal --
     * called directly by dismiss() below (rather than through the cached
     * getNoticeProperty() magic property) so it reflects $dismissedSignature
     * as of *before* dismiss() sets it, not a value memoized earlier in the
     * same request.
     *
     * @return array{signature: string, message: string, href: ?string, buttonLabel: ?string}|null
     */
    private function currentTarget(): ?array
    {
        if ($this->room === null) {
            return null;
        }

        return $this->startedNotice() ?? $this->pendingNotice();
    }

    /**
     * @return array{signature: string, message: string, href: ?string, buttonLabel: ?string}|null
     */
    private function startedNotice(): ?array
    {
        if ($this->room->status !== RoomStatus::Active || $this->room->game_session_id === null) {
            return null;
        }

        if ($this->room->game_session_id === $this->myGameSessionId) {
            return null;
        }

        $type = $this->room->gameSession->type;

        if (! $type->supportsSpectating()) {
            // No RoomSeat/spectator concept for this game (Boggle) -- see
            // GameType::supportsSpectating()'s own docblock.
            return [
                'signature' => "started:{$this->room->game_session_id}",
                'message' => "{$type->label()} is being played right now -- check back to join the next game.",
                'href' => null,
                'buttonLabel' => null,
            ];
        }

        return [
            'signature' => "started:{$this->room->game_session_id}",
            'message' => "The host started a new {$type->label()} game.",
            'href' => route($type->playRoute(), ['room' => $this->room->code]),
            'buttonLabel' => 'Watch',
        ];
    }

    /**
     * @return array{signature: string, message: string, href: ?string, buttonLabel: ?string}|null
     */
    private function pendingNotice(): ?array
    {
        $type = $this->room->pendingGameType();

        if ($type === null) {
            return null;
        }

        return [
            'signature' => "pending:{$type->value}",
            'message' => "The host is setting up {$type->label()} -- hasn't started yet.",
            'href' => route($type->roomRoute(), ['code' => $this->room->code]),
            'buttonLabel' => 'Join',
        ];
    }

    public function dismiss(): void
    {
        $this->dismissedSignature = $this->currentTarget()['signature'] ?? null;
    }
};
?>
<div>
    @if ($this->notice)
        <div class="setup w-full" style="margin-bottom: clamp(8px, 1.4vh, 16px);">
            <p class="status-line">{{ $this->notice['message'] }}</p>
            <div class="flex gap-2" style="justify-content: center;">
                @if ($this->notice['href'])
                    <a href="{{ $this->notice['href'] }}">
                        <button type="button">{{ $this->notice['buttonLabel'] }}</button>
                    </a>
                @endif
                <button type="button" wire:click="dismiss">Dismiss</button>
            </div>
        </div>
    @endif
</div>
