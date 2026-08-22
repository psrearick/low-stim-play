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
    behavior mirrors <livewire:rooms-menu>'s own #rooms-menu-toggle/
    #rooms-menu-panel pattern exactly (see resources/js/app.js's shared
    registerNavbarDropdown() helper) -- same interaction shape, reused
    rather than reinvented.
--}}
@auth
    {{--
        ml-2 -- a little extra room on top of .button-bar .right's own
        10px flex gap, so the avatar doesn't read as glued to the Rooms
        button next to it.

        w-9/h-9 here is only a static fallback (in case app.js hasn't run
        yet, or ever) -- the real sizing is set in JS. The Rooms button's
        own height is font-size/padding-driven via clamp(vmin) (see
        app.css's base `button` rule), not a fixed px value, so a fixed
        size class could only ever match it by coincidence at one
        specific viewport. A pure-CSS version of this (align-self:
        stretch + aspect-ratio, letting <x-avatar> fill it with w-full/
        h-full) was tried first and confirmed live *not* to work
        reliably -- the percentage-height child of a flex-stretched,
        aspect-ratio-sized parent collapsed to a single line's height
        instead of actually stretching. resources/js/app.js's
        syncAvatarToggleSize() measures the real Rooms button and copies
        its height directly instead, the same "measure it, don't guess
        it in CSS" approach this navbar already uses for the dropdown
        panels' own position (see registerNavbarDropdown()'s docblock).
    --}}
    <div class="relative inline-flex ml-2 shrink-0">
        <button type="button" id="user-menu-toggle" aria-haspopup="true" aria-expanded="false"
            aria-label="Account menu" class="p-0 border-none bg-transparent rounded-full w-9 h-9 shrink-0">
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
