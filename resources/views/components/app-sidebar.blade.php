@php
    $links = [
        ['id' => 'dashboard', 'label' => 'My Stats', 'routes' => ['dashboard']],
        ['id' => 'all-games', 'label' => 'All Games', 'routes' => ['profile.games']],
        ['id' => 'boggle-stats', 'label' => \App\Enums\GameType::Boggle->label(), 'routes' => ['profile.boggle', 'profile.boggle.show']],
        ['id' => 'yahtzee-stats', 'label' => \App\Enums\GameType::Yahtzee->label(), 'routes' => ['profile.yahtzee', 'profile.yahtzee.show']],
        ['id' => 'family-feud-stats', 'label' => \App\Enums\GameType::FamilyFeud->label(), 'routes' => ['profile.family-feud', 'profile.family-feud.show']],
        ['id' => 'farkle-stats', 'label' => \App\Enums\GameType::Farkle->label(), 'routes' => ['profile.farkle', 'profile.farkle.show']],
        ['id' => 'apple-match-stats', 'label' => \App\Enums\GameType::AppleMatch->label(), 'routes' => ['profile.apple-match', 'profile.apple-match.show']],
        ['id' => 'clear-the-deck-stats', 'label' => \App\Enums\GameType::ClearTheDeck->label(), 'routes' => ['profile.clear-the-deck', 'profile.clear-the-deck.show']],
        ['id' => 'quartiles-stats', 'label' => \App\Enums\GameType::Quartiles->label(), 'routes' => ['profile.quartiles', 'profile.quartiles.show']],
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

    @foreach ($links as $link)
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
</aside>
