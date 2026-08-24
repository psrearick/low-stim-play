<?php

use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\RoomSeat;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Dropped into each room-capable game's own card on the home page
 * (docs/concerns.md #7) -- a logged-in visitor already in a room for this
 * specific game gets a resume/rejoin link right there, instead of having
 * to detour through the room hub first just to find their way back into a
 * game they're already in.
 */
new class extends Component
{
    public GameType $type;

    /**
     * The first room this account belongs to whose currently relevant game
     * (an active session, else whatever the host is mid-setup on) matches
     * $type, or null if there isn't one. Accepted limitation, same as
     * <livewire:rooms-menu>'s own $this->myRooms use elsewhere: an account
     * seated in more than one room for the *same* game type only ever
     * surfaces the first one found -- rare enough in a casual-game app not
     * to be worth a picker here.
     *
     * @return array{room: Room, active: bool}|null
     */
    public function getResumableProperty(): ?array
    {
        if (Auth::guest() || ! $this->type->supportsRooms()) {
            return null;
        }

        $members = RoomMember::where('user_id', Auth::id())
            ->whereHas('room', fn ($query) => $query->notExpired())
            ->with('room')
            ->get();

        foreach ($members as $member) {
            $room = $member->room;

            if ($room->status === RoomStatus::Active && $room->game_session_id !== null) {
                if ($room->gameSession->type !== $this->type) {
                    continue;
                }

                // Boggle has no RoomSeat/shared-live-board concept at all
                // (see GameType::supportsSpectating()'s own docblock) --
                // room membership alone is enough to resume there, since
                // every other game needs an actual seat to have anything
                // of "yours" to resume.
                $hasSeat = $this->type === GameType::Boggle
                    || RoomSeat::where('room_id', $room->id)->where('user_id', Auth::id())->exists();

                if ($hasSeat) {
                    return ['room' => $room, 'active' => true];
                }

                continue;
            }

            if ($room->pendingGameType() === $this->type) {
                return ['room' => $room, 'active' => false];
            }
        }

        return null;
    }
};
?>
<div>
    @if ($this->resumable)
        <a href="{{ $this->resumable['active']
            ? route($type->playRoute(), ['room' => $this->resumable['room']->code])
            : route($type->roomRoute(), ['code' => $this->resumable['room']->code]) }}">
            <button type="button">{{ $this->resumable['active'] ? 'Resume your seat' : 'Continue setup' }}</button>
        </a>
    @endif
</div>
