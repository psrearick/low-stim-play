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

<div class="flex flex-col items-center gap-6 w-full max-w-2xl">
    <div class="flex items-center gap-4 w-full">
        <x-avatar :name="auth()->user()->name" />
        <div class="flex flex-col">
            <p class="font-bold text-text">{{ auth()->user()->name }}</p>
            @if (auth()->user()->username)
                <a href="{{ route('profile.show', ['username' => auth()->user()->username]) }}" class="text-sm text-accent hover:underline">
                    @{{ auth()->user()->username }} &middot; view public profile
                </a>
            @else
                <a href="{{ route('profile') }}" class="text-sm text-text/65 hover:text-text underline">
                    Choose a username to get a public profile
                </a>
            @endif
        </div>
    </div>

    <x-player-stats :stats="$this->stats" :recent-games="$this->recentGames" />

    <div class="flex gap-2 w-full max-w-md">
        <a href="{{ route('profile') }}" class="flex-1 text-center rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-surface-border text-text">
            Edit profile
        </a>
        <form method="POST" action="{{ route('logout') }}" class="flex-1">
            @csrf
            <button type="submit" class="w-full rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-surface-border text-text">
                Log out
            </button>
        </form>
    </div>
</div>
