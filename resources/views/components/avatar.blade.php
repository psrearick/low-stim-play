@props(['name', 'avatarUrl' => null, 'size' => 'w-12 h-12 text-lg'])

@if ($avatarUrl)
    <img src="{{ $avatarUrl }}" alt="{{ $name }}'s avatar"
        {{ $attributes->merge(['class' => "{$size} rounded-full object-cover shrink-0"]) }}>
@else
    <div {{ $attributes->merge(['class' => "{$size} rounded-full bg-bar text-bg flex items-center justify-center font-bold shrink-0"]) }}>
        {{ strtoupper(mb_substr(trim($name), 0, 1)) }}
    </div>
@endif
