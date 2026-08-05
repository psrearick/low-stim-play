<?php

use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public string $joinID = '';

    public string $guestName = '';

    public ?string $joinError = null;

    public function createRoom(): void
    {
        // Hosting while logged out now works the same way joining already
        // did -- see joinRoom() below for why a guest account (not true
        // anonymity) is required either way. An *existing* guest (already
        // authenticated as one, e.g. from having joined a room earlier)
        // does not get a second throwaway account created on top -- only a
        // genuinely unauthenticated visitor does.
        if (Auth::guest()) {
            Auth::login(User::createGuest($this->guestName));

            if (request()->hasSession()) {
                request()->session()->regenerate();
            }
        }

        $room = Room::create(['host_user_id' => Auth::id()]);

        $this->redirect(route('boggle.room', ['code' => $room->code]));
    }

    public function joinRoom(): void
    {
        $code = strtoupper(trim($this->joinID));
        $room = Room::notExpired()->where('code', $code)->first();

        if (! $room) {
            $this->joinError = 'No active room with that code.';

            return;
        }

        // A room's presence channel always authenticates via the guard (see
        // routes/channels.php) -- there's no "anonymous" option in Laravel's
        // broadcasting auth -- so joining without a real account still needs
        // a real `users` row. Only hosting (createRoom() above) is actually
        // restricted to non-guest accounts.
        if (Auth::guest()) {
            Auth::login(User::createGuest($this->guestName));

            // Guarded rather than unconditional -- Livewire::test() drives
            // this component directly, without the full HTTP kernel/session
            // middleware a real request goes through, so there's no session
            // store attached to request() there.
            if (request()->hasSession()) {
                request()->session()->regenerate();
            }
        }

        $this->redirect(route('boggle.room', ['code' => $room->code]));
    }
};
?>

<x-slot:title>Boggle — Low Stim Play</x-slot>
<x-slot:description>Play Boggle online — a shuffled 4x4 or 5x5 letter grid, timed or untimed, with calm low-contrast visuals and no flashing or motion.</x-slot>

<div class="flex flex-col items-center" style="gap: inherit;">
    <div class="heading">Boggle</div>

    <livewire:boggle-setup />

    <div class="setup">
        @unless (Auth::check() && ! Auth::user()->is_guest)
            <input type="text" wire:model="guestName" maxlength="24" placeholder="Your name (optional)">
        @endunless

        <button type="button" wire:click="createRoom">Host an Online Room</button>

        <div class="status-line">or</div>

        <form wire:submit="joinRoom" class="flex gap-2 w-full">
            <input type="text" id="join" wire:model="joinID" placeholder="Room ID" autocomplete="off" style="flex: 1; text-transform: uppercase;">
            <button type="submit">Join</button>
        </form>

        @if ($joinError)
            <p class="status-line" style="color: var(--warn);">{{ $joinError }}</p>
        @endif
    </div>
</div>

<script>
    // Nothing here sets autofocus, but the room code field still ends up
    // focused (keyboard and all) without the user tapping it -- both when
    // iOS Safari's back-forward cache restores whichever element was
    // focused when the page was left (e.g. after "Join" fails and the user
    // backs out of a room), and, on a completely fresh load, from Safari's
    // verification-code autofill heuristics treating the short, "code"-
    // labeled field as an OTP input. Blur it whenever it's focused before
    // the user has actually interacted with the page.
    let userInteracted = false;
    window.addEventListener('pointerdown', () => { userInteracted = true; }, { once: true, capture: true });

    const blurAutofocusedJoinID = () => {
        const joinID = document.getElementById('join');
        if (! userInteracted && document.activeElement === joinID) {
            joinID.blur();
        }
    };

    window.addEventListener('pageshow', blurAutofocusedJoinID);
    window.addEventListener('load', () => setTimeout(blurAutofocusedJoinID, 0));
</script>
