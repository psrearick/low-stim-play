@props([
    'value' => null,
    'faceDown' => false,
    'selected' => false,
    'clickable' => false,
    'disabled' => false,
    'size' => 'clamp(46px, 9vw, 68px)',
    'sub' => null,
])

@php
    $labels = [11 => 'J', 12 => 'Q', 13 => 'K'];
    $label = $value !== null ? ($labels[$value] ?? $value) : null;

    $classes = ['ctd-card'];
    $classes[] = $faceDown ? 'ctd-card-back' : 'ctd-card-front';
    if ($selected) {
        $classes[] = 'ctd-card-selected';
    }
    if ($clickable && ! $disabled) {
        $classes[] = 'ctd-card-clickable';
    }
    if ($disabled) {
        $classes[] = 'ctd-card-disabled';
    }
@endphp

<div {{ $attributes->merge(['class' => implode(' ', $classes), 'style' => "--ctd-card-w: {$size};"]) }}>
    @if (! $faceDown && $label !== null)
        <span class="ctd-card-value">{{ $label }}</span>
        @if ($sub)
            <span class="ctd-card-sub">{{ $sub }}</span>
        @endif
    @endif
</div>
