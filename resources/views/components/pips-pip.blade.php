@props([
    'value',
])

{{--
    Renders one domino half as a standard die/domino pip face -- a 3x3 grid
    of dot positions (1-9, row-major), only some of which are visible per
    value, rather than plain digit text (docs/DEVLOG.md's Pips entry).
    Shared by pages::pips.play's tray tiles and filled board cells alike, the
    only two places a pip value is ever shown.
--}}
@php
    $positionsByValue = [
        0 => [],
        1 => [5],
        2 => [1, 9],
        3 => [1, 5, 9],
        4 => [1, 3, 7, 9],
        5 => [1, 3, 5, 7, 9],
        6 => [1, 3, 4, 6, 7, 9],
    ];
    $active = $positionsByValue[$value] ?? [];
@endphp

<div {{ $attributes->merge(['class' => 'pips-pip']) }}>
    @for ($position = 1; $position <= 9; $position++)
        <span class="pips-pip-dot @if (in_array($position, $active, true)) is-visible @endif"></span>
    @endfor
</div>
