<?php

use App\Models\User;
use App\Services\Stats\PlayerStatsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public User $user;

    public bool $isPrivate = false;

    public function mount(string $username): void
    {
        $user = User::where('username', $username)->first();

        abort_if($user === null, 404);

        $this->user = $user;
        $this->isPrivate = ! $user->is_public && Auth::id() !== $user->id;
    }

    public function getStatsProperty()
    {
        return $this->isPrivate ? collect() : app(PlayerStatsService::class)->forUser($this->user);
    }

    public function getRecentGamesProperty()
    {
        return $this->isPrivate ? collect() : app(PlayerStatsService::class)->recentGames($this->user);
    }
};
?>

<x-slot:title>{{ $user->name }} — Low Stim Play</x-slot>

<div class="flex flex-col items-center gap-6 w-full max-w-2xl">
    <div class="flex items-center gap-4 w-full">
        <x-avatar :name="$user->name" size="w-16 h-16 text-2xl" />
        <div class="flex flex-col">
            <p class="font-bold text-xl text-text">{{ $user->name }}</p>
            <p class="text-text/65">&commat;{{ $user->username }}</p>
        </div>
    </div>

    @if ($isPrivate)
        <p class="text-sm text-text/65 text-center">This profile is private.</p>
    @else
        <x-player-stats :stats="$this->stats" :recent-games="$this->recentGames" />
    @endif
</div>
