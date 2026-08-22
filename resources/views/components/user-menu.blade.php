{{--
    The navbar-wide avatar dropdown: identity/session actions only (Edit
    Profile, Logout) -- everything else (Stats, Friends, Daily Puzzles, ...)
    now lives in the site-wide menu drawer (<x-app-sidebar />) instead, so
    the two navbar menus stay disjoint rather than both listing the same
    destinations. See the navbar redesign entry in docs/DEVLOG.md ("Site-
    wide design system & chrome") for why: the avatar's job is answering
    "who am I logged in as, and how do I manage that" at a glance (the
    rendered avatar image itself does that even before the dropdown opens),
    which is a different job from browsing site content.

    Plain Blade, not a Livewire component, on purpose -- unlike
    <livewire:rooms-menu>, there's no per-request server state here at
    all (two static links), so there's nothing a Livewire round trip
    would ever need to update. The toggle/panel open-close-position
    behavior mirrors <livewire:rooms-menu>'s own room-list content's
    sibling dropdowns (Game Menu's own toggle/panel) exactly (see
    resources/js/app.js's shared registerNavbarDropdown() helper) --
    same interaction shape, reused rather than reinvented.
--}}
@auth
    {{--
        icon-btn -- the same clamp(34px, 7vmin, 46px) fixed size the
        hamburger Menu and fullscreen-toggle icon buttons already use
        (app.css), rather than the JS-measured size this used to need:
        that measurement copied the Rooms button's own font/padding-
        driven height so the avatar would visually match it, but Rooms
        moved into the Menu drawer (docs/concerns.md) and is no longer a
        sibling in .right at all, so there's nothing left to measure
        against -- a fixed icon size matching the bar's other icon
        buttons is simpler and correct now.
    --}}
    <div class="relative inline-flex shrink-0">
        <button type="button" id="user-menu-toggle" aria-haspopup="true" aria-expanded="false"
            aria-label="Account menu" class="p-0 border-none bg-transparent rounded-full icon-btn shrink-0">
            <x-avatar :name="auth()->user()->name" :avatar-url="auth()->user()->avatarUrl()" size="w-full h-full text-sm" />
        </button>

        {{--
            Link classes match <livewire:rooms-menu>'s own room-row <a>
            styling exactly (plain text + hover highlight -- <a> carries
            none of @layer base's button chrome, only <button> elements
            do). The Logout button is left with that same base button
            look (bordered, backgrounded) rather than stripped, matching
            rooms-menu's own "Join a Room"/"Create a Room" buttons, which
            don't strip it either -- an established, if slightly
            button-y, look for an in-panel action that isn't a plain
            navigation link.
        --}}
        <div id="user-menu-panel" hidden
            class="absolute right-0 top-full mt-2 w-48 rounded-lg border-2 border-surface-border bg-surface p-2 flex flex-col gap-1 z-10">
            <a href="{{ route('profile') }}" class="px-2 py-2 rounded text-sm text-text hover:bg-bg/60">Edit Profile</a>

            <div class="border-t border-surface-border my-1"></div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" id="user-menu-logout" class="w-full text-left px-2 py-2 rounded text-sm text-text hover:bg-bg/60">
                    Logout
                </button>
            </form>
        </div>
    </div>
@endauth
