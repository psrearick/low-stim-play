@props(['href', 'token', 'seconds' => 10])

{{--
    "The host opened the next lobby -- taking you there in 10s" with a Stay
    here escape hatch. Rendered only for a non-host who is *between* games
    in this room (the room hub, a finished game's results screen, an old
    lobby the host moved away from), never mid-game. "Stay here" is
    remembered per $token (Room::pendingGameToken(), fresh each time the host
    opens a lobby) in sessionStorage, so a Livewire re-render or a reload
    doesn't restart the countdown for a lobby this player already declined,
    but the host's *next* lobby still counts down again. Storage access is
    wrapped in try/catch -- it can throw in a private window, and the
    countdown still works without it.
--}}
<div
    wire:key="room-follow-{{ $token }}"
    x-data="{
        remaining: {{ (int) $seconds }},
        stayed: false,
        timer: null,
        storageKey: @js('room-follow-stay:'.$token),
        init() {
            try {
                this.stayed = sessionStorage.getItem(this.storageKey) === '1';
            } catch (e) {}

            if (this.stayed) {
                return;
            }

            this.timer = setInterval(() => {
                this.remaining--;

                if (this.remaining <= 0) {
                    clearInterval(this.timer);
                    window.location.href = @js($href);
                }
            }, 1000);
        },
        destroy() {
            clearInterval(this.timer);
        },
        stay() {
            this.stayed = true;
            clearInterval(this.timer);

            try {
                sessionStorage.setItem(this.storageKey, '1');
            } catch (e) {}
        },
    }"
    class="flex flex-col items-center gap-2"
>
    <p class="status-line" x-show="! stayed" id="room-follow-countdown">
        Taking you there in <span x-text="remaining">{{ (int) $seconds }}</span>s&hellip;
    </p>
    <div class="flex gap-2" style="justify-content: center;">
        <a href="{{ $href }}"><button type="button" id="room-follow-join">Join</button></a>
        <button type="button" id="room-follow-stay" x-show="! stayed" x-on:click="stay()">Stay here</button>
    </div>
</div>
