@props(['room', 'type'])

{{--
    "The host switched to {game}" for a non-host still on a different game's
    room page or lobby -- shared by every {game}.room page and
    <livewire:game-lobby>, which each used to carry an identical copy of
    this markup. Follows automatically after a short countdown (see
    <x-room-follow-countdown>).
--}}
<div class="setup">
    <p class="status-line">The host switched to {{ $type->label() }}.</p>
    <x-room-follow-countdown
        :href="route($type->roomRoute(), ['code' => $room->code])"
        :token="$room->pendingGameToken() ?? $type->value" />
</div>
