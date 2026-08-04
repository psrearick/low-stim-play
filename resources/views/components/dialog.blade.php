@props([
    'id',
    'title' => null,
])

@php
    $titleId = $title ? $id.'-title' : null;
@endphp

<dialog id="{{ $id }}" @if ($titleId) aria-labelledby="{{ $titleId }}" @endif
    {{ $attributes->merge(['class' => 'js-dialog m-auto w-[min(94vw,420px)] rounded-lg border-2 border-surface-border bg-surface p-6 text-text focus:outline-none']) }}>
    @if ($title)
        <div class="text-bar font-bold tracking-wide text-lg mb-4" id="{{ $titleId }}">{{ $title }}</div>
    @endif

    {{ $slot }}
</dialog>
