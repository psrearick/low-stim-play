@php
    $links = [
        ['id' => 'dashboard', 'label' => 'Dashboard', 'routes' => ['dashboard']],
        ['id' => 'all-games', 'label' => 'All Games', 'routes' => ['profile.games']],
        ['id' => 'boggle-stats', 'label' => \App\Enums\GameType::Boggle->label().' Stats', 'routes' => ['profile.boggle', 'profile.boggle.show']],
        ['id' => 'yahtzee-stats', 'label' => \App\Enums\GameType::Yahtzee->label().' Stats', 'routes' => ['profile.yahtzee', 'profile.yahtzee.show']],
        ['id' => 'family-feud-stats', 'label' => \App\Enums\GameType::FamilyFeud->label().' Stats', 'routes' => ['profile.family-feud', 'profile.family-feud.show']],
        ['id' => 'farkle-stats', 'label' => \App\Enums\GameType::Farkle->label().' Stats', 'routes' => ['profile.farkle', 'profile.farkle.show']],
        ['id' => 'apple-match-stats', 'label' => \App\Enums\GameType::AppleMatch->label().' Stats', 'routes' => ['profile.apple-match', 'profile.apple-match.show']],
        ['id' => 'edit-profile', 'label' => 'Edit Profile', 'routes' => ['profile']],
    ];
@endphp

<div class="app-sidebar-backdrop" id="app-sidebar-backdrop"></div>

<aside class="app-sidebar" id="app-sidebar" aria-label="Main navigation">
    @foreach ($links as $link)
        <a id="sidebar-link-{{ $link['id'] }}" href="{{ route($link['routes'][0]) }}"
            class="app-sidebar-link {{ request()->routeIs(...$link['routes']) ? 'active' : '' }}"
            data-sidebar-close>
            {{ $link['label'] }}
        </a>
    @endforeach

    <div class="app-sidebar-divider"></div>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="app-sidebar-link" data-sidebar-close>Log Out</button>
    </form>
</aside>
