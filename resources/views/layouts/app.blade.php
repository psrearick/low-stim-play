<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
        <link rel="manifest" href="/manifest.json">
        <meta name="theme-color" content="#2c3035">

        <title>{{ $title ?? config('app.name') }}</title>
        <meta name="description" content="{{ $description ?? 'Low Stim Play is a small collection of low-contrast, no-flash, no-motion web games -- Word Grid, Yacht, and Survey Says. Built to be gentle on migraines, vertigo, and sensitive eyes.' }}">
        <meta property="og:site_name" content="Low Stim Play">
        <meta property="og:title" content="{{ $title ?? config('app.name') }}">
        <meta property="og:description" content="{{ $description ?? 'A small collection of low-contrast, no-flash, no-motion web games. Built to be gentle on migraines, vertigo, and sensitive eyes.' }}">
        <meta property="og:type" content="website">
        <meta property="og:image" content="/og-image.png">
        <meta name="twitter:card" content="summary">
        <meta name="twitter:title" content="{{ $title ?? config('app.name') }}">
        <meta name="twitter:description" content="{{ $description ?? 'A small collection of low-contrast, no-flash, no-motion web games. Built to be gentle on migraines, vertigo, and sensitive eyes.' }}">
        <meta name="twitter:image" content="/web-app-manifest-512x512.png">
        <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96">
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
        <link rel="shortcut icon" href="/favicon.ico">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
        <meta name="apple-mobile-web-app-title" content="LowStim">

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
    <body class="scrollable{{ isset($bodyClass) ? ' '.$bodyClass : '' }}">
        <div class="button-bar{{ isset($manyButtons) ? ' many-buttons' : '' }}">
            <div class="left">
                <a href="{{ url('/') }}"><button type="button">Home</button></a>
                {{--
                    Site-wide, not just on the homepage or the two puzzle
                    games' own pages -- the whole point of a daily puzzle is
                    that it's easy to get to from anywhere, and guests can
                    browse/play the archive same as any random puzzle, so
                    this isn't gated @auth either.
                --}}
                <a href="{{ route('daily-puzzles') }}"><button type="button" id="daily-puzzles-nav-link">Daily Puzzles</button></a>
                @isset($sidebar)
                    <button type="button" id="sidebar-menu-toggle" aria-label="Open navigation menu">Menu</button>
                @endisset
                @isset($rules)
                    <button type="button" id="rules-btn" aria-label="Rules">?</button>
                @endisset
            </div>
            <div class="right">
                {{--
                    Rendered directly here (not a teleport target) --
                    unlike #game-actions below, this needs to appear on
                    every page, including plain (non pages::) Blade views
                    like welcome.blade.php that never trigger a Livewire
                    round trip of their own for a teleport to react to.
                --}}
                <livewire:rooms-menu />
                {{--
                    A stable teleport target (see Livewire's @teleport
                    directive), not a slot -- game-specific right-side
                    buttons (End Game/New Game, Reveal, ...) depend on
                    Livewire state that changes after the initial page
                    load, and a named layout slot like the old $barRight
                    is only ever evaluated once at that first render: it
                    lives outside the component's own root element, so
                    it's never touched by the AJAX diff a wire:click
                    triggers afterwards. display: contents so teleported
                    buttons still lay out as direct flex items of .right,
                    picking up its gap, rather than as a nested box.
                --}}
                <div id="game-actions" style="display: contents;"></div>
                {{--
                    A plain slot, not a teleport target -- #game-actions
                    above exists specifically because a Livewire round trip
                    only re-renders that component's own root, never the
                    surrounding layout. Non-Livewire pages (currently just
                    welcome.blade.php's guest/auth links) never trigger that
                    round trip in the first place, so a normal slot works
                    exactly like $rules/$fullscreenToggle below.
                --}}
                {{ $barRight ?? '' }}
                @isset($fullscreenToggle)
                    <button type="button" id="chrome-toggle-btn" class="fullscreen-toggle" aria-pressed="false" aria-label="Hide menu bars for fullscreen">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" aria-hidden="true">
                            <g class="icon-expand">
                                <path d="M8 3H5a2 2 0 0 0-2 2v3" />
                                <path d="M21 8V5a2 2 0 0 0-2-2h-3" />
                                <path d="M3 16v3a2 2 0 0 0 2 2h3" />
                                <path d="M16 21h3a2 2 0 0 0 2-2v-3" />
                            </g>
                            <g class="icon-collapse">
                                <path d="M8 3v3a2 2 0 0 1-2 2H3" />
                                <path d="M21 8h-3a2 2 0 0 1-2-2V3" />
                                <path d="M3 16h3a2 2 0 0 1 2 2v3" />
                                <path d="M16 21v-3a2 2 0 0 1 2-2h3" />
                            </g>
                        </svg>
                    </button>
                @endisset
                {{--
                    Always the last item in .right -- rendered after every
                    game-specific/page-specific button above (#game-actions,
                    $barRight, the fullscreen toggle) so the avatar's own
                    position never shifts depending on how many other
                    buttons a given page adds next to it.
                --}}
                <x-user-menu />
            </div>
        </div>

        @isset($rules)
            <dialog class="rules-dialog" id="rules-dialog" aria-labelledby="rules-dialog-title" tabindex="-1">
                <div class="rules-dialog-header">
                    <div class="rules-dialog-title" id="rules-dialog-title">{{ $rulesTitle ?? 'Rules' }}</div>
                    <button type="button" class="rules-dialog-close" id="rules-dialog-close" aria-label="Close">&times;</button>
                </div>

                {{ $rules }}
            </dialog>
        @endisset

        @isset($sidebar)
            <div class="app-shell">
                <x-app-sidebar />
                <main class="app-main">
                    {{ $slot ?? '' }}
                    @yield('content')
                </main>
            </div>
        @else
            {{ $slot ?? '' }}
            @yield('content')
        @endisset

        <footer class="site-footer">
            <a href="{{ route('about') }}">About</a>
            <a href="{{ route('privacy') }}">Privacy Policy</a>
            <a href="https://ko-fi.com/philliprearick" target="_blank" rel="noopener">Support Me</a>
            <button type="button" data-theme-toggle class="theme-toggle" aria-label="Switch to light theme">Light Theme</button>
        </footer>

        @livewireScripts

        <script>
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => {
                    navigator.serviceWorker.register('/sw.js');
                });
            }
        </script>
    </body>
</html>
