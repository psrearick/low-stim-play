@php
    // Game entries link to each game's own play page now, not its stats
    // page (docs/concerns.md) -- the per-game *stats* pages moved to
    // <x-stats-sidebar />'s dedicated right-hand panel instead (see that
    // component), reachable from any stats page rather than from here.
    // href is given explicitly (not GameType::playRoute(), which points
    // Boggle at `boggle.play` -- the direct solo-play screen) since
    // Boggle's own actual entry point is the lobby/setup screen
    // (`boggle.lobby`), matching welcome.blade.php's own game-card "Play"
    // button for every game.
    $gameLinks = [
        ['id' => 'boggle', 'label' => \App\Enums\GameType::Boggle->label(), 'href' => route('boggle.lobby'), 'routes' => ['boggle.lobby', 'boggle.play', 'boggle.group', 'boggle.room']],
        ['id' => 'yahtzee', 'label' => \App\Enums\GameType::Yahtzee->label(), 'href' => route('yahtzee.play'), 'routes' => ['yahtzee.play', 'yahtzee.roller', 'yahtzee.room']],
        ['id' => 'family-feud', 'label' => \App\Enums\GameType::FamilyFeud->label(), 'href' => route('family-feud.play'), 'routes' => ['family-feud.play', 'family-feud.room']],
        ['id' => 'farkle', 'label' => \App\Enums\GameType::Farkle->label(), 'href' => route('farkle.play'), 'routes' => ['farkle.play', 'farkle.room']],
        ['id' => 'apple-match', 'label' => \App\Enums\GameType::AppleMatch->label(), 'href' => route('apple-match.play'), 'routes' => ['apple-match.play', 'apple-match.room']],
        ['id' => 'clear-the-deck', 'label' => \App\Enums\GameType::ClearTheDeck->label(), 'href' => route('clear-the-deck.play'), 'routes' => ['clear-the-deck.play', 'clear-the-deck.room']],
        ['id' => 'quartiles', 'label' => \App\Enums\GameType::Quartiles->label(), 'href' => route('quartiles.play'), 'routes' => ['quartiles.play']],
        ['id' => 'wordle', 'label' => \App\Enums\GameType::Wordle->label(), 'href' => route('wordle.play'), 'routes' => ['wordle.play']],
        ['id' => 'spelling-bee', 'label' => \App\Enums\GameType::SpellingBee->label(), 'href' => route('spelling-bee.play'), 'routes' => ['spelling-bee.play']],
        ['id' => 'punchline', 'label' => \App\Enums\GameType::Punchline->label(), 'href' => route('punchline.play'), 'routes' => ['punchline.play', 'punchline.room']],
        ['id' => 'hoax', 'label' => \App\Enums\GameType::Hoax->label(), 'href' => route('hoax.play'), 'routes' => ['hoax.play', 'hoax.room']],
    ];

    // Its own section (docs/concerns.md) -- separate from the game links
    // above (which now go straight to gameplay) so "go play a game" and
    // "go look at stats" read as two distinct groups instead of one long
    // mixed list.
    $statsLinks = [
        ['id' => 'dashboard', 'label' => 'My Stats', 'routes' => ['dashboard']],
        ['id' => 'all-games', 'label' => 'All Games', 'routes' => ['profile.games']],
    ];

    $pendingFriendRequests = auth()->check()
        ? app(\App\Services\Social\FriendService::class)->pendingReceived(auth()->user())->count()
        : 0;
@endphp

<div class="app-sidebar-backdrop" id="app-sidebar-backdrop"></div>

{{--
    The one site-wide navigation drawer, used on every page (not just
    Dashboard/Profile/Stats pages as before) -- see the navbar redesign
    entry in docs/DEVLOG.md ("Site-wide design system & chrome") for why:
    the old top bar showed a different combination of buttons on nearly
    every page type and wrapped into multiple rows on mobile once a game
    page's own action buttons piled up next to it. This drawer now carries
    every piece of site-wide navigation (previously scattered across the
    bar itself, the Rooms-menu-adjacent guest links, and this sidebar's
    stats-only links), leaving the bar itself down to a fixed, small set
    of buttons that never grows per-page. "Edit Profile" and "Log Out"
    stay in the navbar's own avatar dropdown (<x-user-menu />) rather than
    here -- those are account/session actions, not site content, so they
    keep their own separate, identity-anchored affordance instead of
    being mixed in with content links.
--}}
<aside class="app-sidebar" id="app-sidebar" aria-label="Main navigation">
    <a id="sidebar-link-home" href="{{ url('/') }}"
        class="app-sidebar-link {{ request()->is('/') ? 'active' : '' }}"
        data-sidebar-close>
        Home
    </a>
    <a id="sidebar-link-daily-puzzles" href="{{ route('daily-puzzles') }}"
        class="app-sidebar-link {{ request()->routeIs('daily-puzzles') ? 'active' : '' }}"
        data-sidebar-close>
        Daily Puzzles
    </a>

    <div class="app-sidebar-divider"></div>

    {{--
        Folded in rather than kept as its own persistent bar dropdown
        (docs/concerns.md) -- <livewire:rooms-menu>'s own markup no longer
        has a toggle/panel wrapper of its own, so its content (room list,
        Join/Create a Room) renders directly here, always visible once
        this drawer is open.
    --}}
    <livewire:rooms-menu />

    <div class="app-sidebar-divider"></div>

    @foreach ($gameLinks as $link)
        <a id="sidebar-link-{{ $link['id'] }}" href="{{ $link['href'] }}"
            class="app-sidebar-link {{ request()->routeIs(...$link['routes']) ? 'active' : '' }}"
            data-sidebar-close>
            {{ $link['label'] }}
        </a>
    @endforeach

    <div class="app-sidebar-divider"></div>

    @foreach ($statsLinks as $link)
        <a id="sidebar-link-{{ $link['id'] }}" href="{{ route($link['routes'][0]) }}"
            class="app-sidebar-link {{ request()->routeIs(...$link['routes']) ? 'active' : '' }}"
            data-sidebar-close>
            {{ $link['label'] }}
        </a>
    @endforeach

    @auth
        <div class="app-sidebar-divider"></div>

        <a id="sidebar-link-friends" href="{{ route('friends') }}"
            class="app-sidebar-link {{ request()->routeIs('friends') ? 'active' : '' }} flex items-center justify-between"
            data-sidebar-close>
            Friends
            @if ($pendingFriendRequests > 0)
                <span id="sidebar-friends-badge" class="min-w-[1.1rem] h-[1.1rem] px-1 rounded-full bg-accent text-bg text-[0.65rem] font-bold leading-[1.1rem] text-center">
                    {{ $pendingFriendRequests }}
                </span>
            @endif
        </a>
    @endauth

    <div class="app-sidebar-divider"></div>

    <a id="sidebar-link-about" href="{{ route('about') }}"
        class="app-sidebar-link {{ request()->routeIs('about') ? 'active' : '' }}"
        data-sidebar-close>
        About
    </a>
    <a id="sidebar-link-privacy" href="{{ route('privacy') }}"
        class="app-sidebar-link {{ request()->routeIs('privacy') ? 'active' : '' }}"
        data-sidebar-close>
        Privacy Policy
    </a>

    @guest
        <div class="app-sidebar-divider"></div>

        <a id="sidebar-link-login" href="{{ route('login') }}" class="app-sidebar-link" data-sidebar-close>Sign In</a>
        <a id="sidebar-link-register" href="{{ route('register') }}" class="app-sidebar-link" data-sidebar-close>Create an Account</a>
    @endguest

    <div class="app-sidebar-divider"></div>

    {{--
        Moved here from the site footer (docs/concerns.md) -- the bottom
        of this drawer, not `data-sidebar-close`d, since toggling the
        theme isn't a navigation action: closing the drawer on click would
        hide the very page repaint the button just triggered. Text/
        aria-label ("Light Theme"/"Dark Theme") are set by app.js's
        existing `[data-theme-toggle]` handler, unchanged by this move.
    --}}
    <button type="button" data-theme-toggle class="app-sidebar-link" aria-label="Switch to light theme">Light Theme</button>
</aside>
