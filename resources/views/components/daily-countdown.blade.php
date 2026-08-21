@props(['resetsAt'])

{{--
    A plain Alpine countdown (no Livewire $wire needed, unlike Boggle's own
    in-game timer) -- self-contained enough to drop into either game's
    reveal screen once today's daily is already done. $resetsAt is a Unix
    timestamp for the next daily's reset (tomorrow's start of day).
--}}
<div
    x-data="{
        display: '',
        tick() {
            const remaining = Math.max(0, {{ (int) $resetsAt }} - Math.floor(Date.now() / 1000));
            const h = Math.floor(remaining / 3600);
            const m = Math.floor((remaining % 3600) / 60);
            const s = remaining % 60;
            this.display = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        },
    }"
    x-init="tick(); setInterval(() => tick(), 1000)"
    class="flex flex-col items-center gap-1"
>
    <span class="text-xs text-text/65">Next daily puzzle in</span>
    <span class="text-lg font-bold text-text" x-text="display" id="daily-countdown"></span>
</div>
