@props([
    'id',
    'title',
    'message' => null,
    'cancelLabel' => 'Cancel',
])

<x-dialog :id="$id" :title="$title" {{ $attributes }}>
    @if ($message)
        <p class="text-sm text-text/80 text-center mb-4">{{ $message }}</p>
    @endif

    {{ $slot ?? '' }}

    <div class="flex gap-3 w-full mt-2">
        <button type="button" id="{{ $id }}-cancel" data-dialog-close
            class="flex-1 rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-surface-border text-text">
            {{ $cancelLabel }}
        </button>

        {{ $confirm }}
    </div>
</x-dialog>
