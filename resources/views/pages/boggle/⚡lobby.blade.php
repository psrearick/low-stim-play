<?php

use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public string $joinCode = '';

    public string $guestName = '';

    public ?string $joinError = null;

    public function createRoom(): void
    {
        // Belt-and-suspenders alongside the template only showing this
        // button to a real (non-guest) account -- hosting a room always
        // needs one, unlike joining one.
        abort_unless(Auth::check() && ! Auth::user()->is_guest, 403);

        $room = Room::create(['host_user_id' => Auth::id()]);

        $this->redirect(route('boggle.room', ['code' => $room->code]));
    }

    public function joinRoom(): void
    {
        $code = strtoupper(trim($this->joinCode));
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
        @if (Auth::check() && ! Auth::user()->is_guest)
            <button type="button" wire:click="createRoom">Host an Online Room</button>

            <div class="status-line">or</div>
        @endif

        @unless (Auth::check() && ! Auth::user()->is_guest)
            <input type="text" wire:model="guestName" maxlength="24" placeholder="Your name (optional)">
        @endunless

        <form wire:submit="joinRoom" class="flex gap-2 w-full">
            <input type="text" id="join-code" wire:model="joinCode" maxlength="6" placeholder="Room code" autocomplete="off" style="flex: 1; text-transform: uppercase;">
            <button type="submit">Join</button>
        </form>

        @if ($joinError)
            <p class="status-line" style="color: var(--warn);">{{ $joinError }}</p>
        @endif

        @unless (Auth::check() && ! Auth::user()->is_guest)
            <p class="status-line"><a href="{{ route('login') }}" style="color: var(--bar);">Sign in</a> to host an online room.</p>
        @endunless
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

    const blurAutofocusedJoinCode = () => {
        const joinCode = document.getElementById('join-code');
        if (! userInteracted && document.activeElement === joinCode) {
            joinCode.blur();
        }
    };

    window.addEventListener('pageshow', blurAutofocusedJoinCode);
    window.addEventListener('load', () => setTimeout(blurAutofocusedJoinCode, 0));
</script>
