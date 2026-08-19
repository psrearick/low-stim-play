{{--
    The navbar-wide avatar dropdown (docs/TODO.md's former #24): "Edit
    Profile" and "Log Out" used to only live in the sidebar (so only on
    Dashboard/Profile/Stats pages, see app-sidebar.blade.php) or the
    homepage's own one-off $barRight slot (welcome.blade.php) -- this
    replaces both, rendered once here in layouts/app.blade.php so it
    appears on every authenticated page, sidebar or not.

    Plain Blade, not a Livewire component, on purpose -- unlike
    <livewire:rooms-menu>, there's no per-request server state here at
    all (three static links), so there's nothing a Livewire round trip
    would ever need to update. The toggle/panel open-close-position
    behavior mirrors <livewire:rooms-menu>'s own #rooms-menu-toggle/
    #rooms-menu-panel pattern exactly (see resources/js/app.js's shared
    registerNavbarDropdown() helper) -- same interaction shape, reused
    rather than reinvented.
--}}
@auth
    <div class="relative inline-flex">
        <button type="button" id="user-menu-toggle" aria-haspopup="true" aria-expanded="false"
            aria-label="Account menu" class="p-0 border-none bg-transparent rounded-full">
            <x-avatar :name="auth()->user()->name" :avatar-url="auth()->user()->avatarUrl()" size="w-9 h-9 text-sm" />
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
            <a href="{{ route('dashboard') }}" class="px-2 py-2 rounded text-sm text-text hover:bg-bg/60">Stats</a>
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
