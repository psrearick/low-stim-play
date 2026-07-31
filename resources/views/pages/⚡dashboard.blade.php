<?php

use App\Services\Stats\PlayerStatsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public function getStatsProperty()
    {
        return app(PlayerStatsService::class)->forUser(Auth::user());
    }

    public function getRecentGamesProperty()
    {
        return app(PlayerStatsService::class)->recentGames(Auth::user());
    }
};
?>

<x-slot:title>Dashboard — Low Stim Play</x-slot>

<div class="flex flex-col items-center gap-6 w-full max-w-2xl">
    {{--
        Teleported (see layouts/app.blade.php's #game-actions anchor)
        rather than a layout $barRight slot -- this is a Livewire
        full-page component, and a plain layout slot is only ever
        evaluated once at the very first render; it lives outside the
        component's own root element, so a later Livewire round trip
        would never touch it again. Matches every other Livewire page's
        bar-right content (Boggle/Yahtzee/Family Feud/profile), even
        though this particular page has no wire:click actions today.
        Both buttons need one wrapping `display: contents` element --
        @teleport only relocates a single root node, so two bare
        siblings directly inside it silently drops everything after
        the first (confirmed: only "Edit Profile" made it across
        without this wrapper).
    --}}
    @teleport('#game-actions')
        <div style="display: contents;">
            <a href="{{ route('profile') }}"><button type="button">Edit Profile</button></a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">Log Out</button>
            </form>
        </div>
    @endteleport

    <div class="flex items-center gap-4 w-full">
        <x-avatar :name="auth()->user()->name" />
        <div class="flex flex-col">
            <p class="font-bold text-text">{{ auth()->user()->name }}</p>
            @if (auth()->user()->username)
                <a href="{{ route('profile.show', ['username' => auth()->user()->username]) }}" class="text-sm text-text/65 hover:text-text underline">
                    &commat;{{ auth()->user()->username }} &middot; view public profile
                </a>
            @else
                <a href="{{ route('profile') }}" class="text-sm text-text/65 hover:text-text underline">
                    Choose a username to get a public profile
                </a>
            @endif
        </div>
    </div>

    <x-player-stats :stats="$this->stats" :recent-games="$this->recentGames" :linkable="true" />
</div>
