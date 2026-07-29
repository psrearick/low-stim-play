<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#2c3035">

        <title>{{ $title ?? config('app.name') }}</title>

        {{--
            Applied before paint to avoid a flash of the wrong theme. Dark is
            this app's default appearance (mirrors legacy/theme.js); light is
            opt-in and persisted in localStorage.
        --}}
        <script>
            (function () {
                var stored = localStorage.getItem('theme');
                document.documentElement.classList.toggle('dark', stored !== 'light');
            })();
        </script>

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body class="min-h-screen bg-bg text-text flex flex-col items-center justify-center gap-8 p-4 font-sans">
        <main class="w-full flex flex-col items-center">
            {{ $slot ?? '' }}
            @yield('content')
        </main>

        <footer class="fixed inset-x-0 bottom-0 flex justify-between gap-8 px-4 py-3 border-t border-surface-border bg-bg text-xs tracking-wide">
            <a href="{{ url('/') }}" class="text-text/65 hover:text-text">Low Stim Play</a>
            <button type="button" data-theme-toggle class="text-text/65 hover:text-text">Light theme</button>
        </footer>

        @livewireScripts
    </body>
</html>
