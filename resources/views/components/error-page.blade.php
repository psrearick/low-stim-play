@props(['code', 'heading', 'message'])

{{--
    docs/concerns.md: error pages (404 included) need to use the app's
    current color scheme and link back home. No resources/views/errors/
    directory existed before this -- Laravel fell all the way back to its
    own plain, unstyled default views (no dark mode, no nav, no home link).

    @component('layouts.app'), the same pattern welcome.blade.php uses for
    a static (non-Livewire) page -- gets the real dark-mode-by-default
    color tokens and the layout's own persistent "Play" button in the
    button-bar for free, so the requirement is met without duplicating
    that chrome here.
--}}
@component('layouts.app')
    @slot('title') {{ $code }} — Low Stim Play @endslot

    <div class="flex flex-col items-center gap-4 text-center px-4">
        <div class="heading">{{ $heading }}</div>
        <p class="status-line">{{ $message }}</p>
        <a href="{{ url('/') }}"><button type="button">Go to Play</button></a>
    </div>
@endcomponent
