@props(['heading' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center gap-5 w-full max-w-md bg-surface border-2 border-surface-border rounded-lg p-8']) }}>
    @if ($heading)
        <h1 class="text-accent font-bold tracking-wide text-2xl text-center">{{ $heading }}</h1>
    @endif

    {{ $slot }}
</div>
