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
@endphp

<div class="app-sidebar-backdrop" id="app-sidebar-backdrop"></div>

{{--
    "Edit Profile" and "Log Out" moved into the navbar's own avatar
    dropdown (<x-user-menu />, layouts/app.blade.php) -- docs/TODO.md's
    former #24 -- so this sidebar is per-game stats navigation only now.
--}}
<aside class="app-sidebar" id="app-sidebar" aria-label="Main navigation">
    @foreach ($links as $link)
        <a id="sidebar-link-{{ $link['id'] }}" href="{{ route($link['routes'][0]) }}"
            class="app-sidebar-link {{ request()->routeIs(...$link['routes']) ? 'active' : '' }}"
            data-sidebar-close>
            {{ $link['label'] }}
        </a>
    @endforeach
</aside>
