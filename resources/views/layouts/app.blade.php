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
        {{--
            The top bar is a fixed, small set of buttons on every page --
            a hamburger Menu icon on the left, [Game (game pages only),
            Avatar] on the right -- rather than a per-page mix of buttons
            that varied in count and grew crowded on game pages (see the
            navbar redesign entry in docs/DEVLOG.md, "Site-wide design
            system & chrome"). Home and Rooms moved into the Menu drawer
            itself (docs/concerns.md) rather than staying persistent bar
            items -- everything that used to add its own bar button (Home,
            Daily Puzzles, Rooms, Rules, per-game action buttons, the
            fullscreen toggle) now lives inside one of the two dropdown
            menus instead, so the bar itself never grows regardless of how
            many actions a given game needs, and never wraps onto a second
            line on mobile.
        --}}
        <div class="button-bar">
            <div class="left">
                <button type="button" id="sidebar-menu-toggle" class="icon-btn" aria-label="Open navigation menu">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M4 6h16" />
                        <path d="M4 12h16" />
                        <path d="M4 18h16" />
                    </svg>
                </button>
            </div>
            <div class="right">
                @if (isset($rules) || isset($fullscreenToggle))
                    {{--
                        Toggles the right-side Game Menu drawer below
                        (#game-menu-sidebar) -- the one place Rules and this
                        game's own action buttons (End Game/New Game,
                        Reveal, Watch only, ...) live now, instead of each
                        being its own bar button. Gated on $rules or
                        $fullscreenToggle rather than a new dedicated slot --
                        every game-play page that needs either already sets
                        it (Quartiles sets only $rules, no fullscreen
                        toggle; every other game-play page sets both), and
                        room-lobby pages (which need neither) set neither.

                        Bound directly in resources/js/app.js (like
                        #sidebar-menu-toggle/#stats-sidebar-toggle), not
                        data-dialog-open -- this used to be a real <x-dialog>
                        modal (see docs/DEVLOG.md's TODO Task 33 entry for
                        why it's an off-canvas drawer instead now, mirroring
                        the site menu/stats drawers).
                    --}}
                    <button type="button" id="game-menu-toggle">
                        Game
                    </button>

                    @isset($fullscreenToggle)
                        {{--
                            Kept as its own persistent icon button,
                            deliberately NOT inside the Game Menu drawer
                            below -- this is the one control that has to
                            stay reachable even after it hides everything
                            else in the bar (see app.css's body.chrome-
                            hidden rules), including the Game button itself.
                        --}}
                        <button type="button" id="chrome-toggle-btn" class="icon-btn fullscreen-toggle" aria-pressed="false" aria-label="Hide menu bars for fullscreen">
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
                @endif

                @isset($sidebar)
                    {{--
                        Stats pages only (opted in via `<x-slot:sidebar>1
                        </x-slot:sidebar>`, the same pages App\Enums\
                        GameType per-game stats routes cover) -- opens
                        <x-stats-sidebar />, the "stats menu" of links to
                        every game's own stats page (docs/concerns.md).
                        Mirrors #sidebar-menu-toggle's own icon-button
                        look/position but on .right instead of .left, since
                        the site menu drawer's own hamburger already
                        occupies that spot.
                    --}}
                    <button type="button" id="stats-sidebar-toggle" class="icon-btn" aria-label="Open stats menu">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M18 20V10" />
                            <path d="M12 20V4" />
                            <path d="M6 20v-6" />
                        </svg>
                    </button>
                @endisset

                {{--
                    Always the last item in .right -- rendered after every
                    other page-specific control above so the avatar's own
                    position never shifts depending on what a given page
                    adds next to it.
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

        <x-app-sidebar />

        @isset($sidebar)
            <x-stats-sidebar />
        @endisset

        @if (isset($rules) || isset($fullscreenToggle))
            {{--
                The Game Menu drawer -- an off-canvas sidebar like
                <x-app-sidebar />/<x-stats-sidebar /> above, anchored to the
                right (docs/TODO.md's former Task 33; previously a real
                <x-dialog> modal, adopted specifically to stop it
                overlapping the score/timer HUD -- see the navbar redesign
                entries in docs/DEVLOG.md). #game-actions has to exist
                whenever a page might @teleport into it regardless of
                whether this particular page sets $rules or
                $fullscreenToggle -- Livewire's own teleport throws on a
                missing target the moment that page's component tries to
                render into it, not a silent no-op.
            --}}
            <div class="app-sidebar-backdrop" id="game-menu-backdrop"></div>

            <aside class="app-sidebar game-menu-sidebar" id="game-menu-sidebar" aria-label="Game menu">
                @isset($rules)
                    <button type="button" id="rules-btn" class="app-sidebar-link">Rules</button>
                    <div class="app-sidebar-divider"></div>
                @endisset

                {{--
                    A stable teleport target (see Livewire's @teleport
                    directive), not a slot -- game-specific action buttons
                    (End Game/New Game, Reveal, ...) depend on Livewire
                    state that changes after the initial page load, and a
                    named layout slot is only ever evaluated once at that
                    first render: it lives outside the component's own root
                    element, so it's never touched by the AJAX diff a
                    wire:click triggers afterwards. display: contents so
                    teleported buttons lay out as direct flex items of this
                    aside's own flex-col, picking up its gap, rather than as
                    a nested box.
                --}}
                <div id="game-actions" style="display: contents;"></div>
            </aside>
        @endif

        {{--
            A teleport target for <livewire:rooms-menu>'s own "Host is
            setting up..."/"The game has started..." notifications (docs/
            concerns.md) -- sitting at the body level rather than inside
            <x-app-sidebar /> above. That component renders those toasts as
            position: fixed, but .app-sidebar is display: none while
            closed, and display: none on an ancestor un-renders its entire
            subtree regardless of what position any descendant declares --
            confirmed live (the toast was invisible on every page until the
            drawer happened to be open). Teleporting them here instead
            keeps the notification listener/state on the one Livewire
            component already mounted site-wide (rooms-menu, itself
            rendered inside the drawer for the room list/Join/Create UI),
            while letting just this one piece of its markup render
            somewhere the drawer's own open/closed state can't hide.
        --}}
        <div id="room-notifications-anchor"></div>

        {{ $slot ?? '' }}
        @yield('content')

        <footer class="site-footer">
            <a href="{{ route('about') }}">About</a>
            <a href="{{ route('privacy') }}">Privacy Policy</a>
            <a href="https://ko-fi.com/philliprearick" target="_blank" rel="noopener">Support Me</a>
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
