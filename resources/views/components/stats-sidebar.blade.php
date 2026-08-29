@php
    // The per-game *stats* links that used to live in <x-app-sidebar />
    // itself, before docs/concerns.md moved that drawer's game entries
    // over to gameplay -- this is where they live now: a second,
    // stats-only drawer, opened from a toggle button that only renders on
    // stats pages (see layouts/app.blade.php's `@isset($sidebar)` gate).
    $statsLinks = [
        ['id' => 'dashboard', 'label' => 'My Stats', 'routes' => ['dashboard']],
        ['id' => 'all-games', 'label' => 'All Games', 'routes' => ['profile.games']],
        ['id' => 'boggle', 'label' => \App\Enums\GameType::Boggle->label(), 'routes' => ['profile.boggle', 'profile.boggle.show']],
        ['id' => 'yahtzee', 'label' => \App\Enums\GameType::Yahtzee->label(), 'routes' => ['profile.yahtzee', 'profile.yahtzee.show']],
        ['id' => 'family-feud', 'label' => \App\Enums\GameType::FamilyFeud->label(), 'routes' => ['profile.family-feud', 'profile.family-feud.show']],
        ['id' => 'farkle', 'label' => \App\Enums\GameType::Farkle->label(), 'routes' => ['profile.farkle', 'profile.farkle.show']],
        ['id' => 'apple-match', 'label' => \App\Enums\GameType::AppleMatch->label(), 'routes' => ['profile.apple-match', 'profile.apple-match.show']],
        ['id' => 'clear-the-deck', 'label' => \App\Enums\GameType::ClearTheDeck->label(), 'routes' => ['profile.clear-the-deck', 'profile.clear-the-deck.show']],
        ['id' => 'quartiles', 'label' => \App\Enums\GameType::Quartiles->label(), 'routes' => ['profile.quartiles', 'profile.quartiles.show']],
        ['id' => 'wordle', 'label' => \App\Enums\GameType::Wordle->label(), 'routes' => ['profile.wordle', 'profile.wordle.show']],
        ['id' => 'spelling-bee', 'label' => \App\Enums\GameType::SpellingBee->label(), 'routes' => ['profile.spelling-bee', 'profile.spelling-bee.show']],
        ['id' => 'punchline', 'label' => \App\Enums\GameType::Punchline->label(), 'routes' => ['profile.punchline', 'profile.punchline.show']],
        ['id' => 'hoax', 'label' => \App\Enums\GameType::Hoax->label(), 'routes' => ['profile.hoax', 'profile.hoax.show']],
    ];
@endphp

<div class="app-sidebar-backdrop" id="stats-sidebar-backdrop"></div>

{{--
    Same overlay pattern as <x-app-sidebar /> (instant show/hide, no
    slide/animation -- see that component's own comment for why), just
    anchored to the right edge instead of the left (.stats-sidebar in
    app.css) so it reads as a distinct, second drawer rather than the
    same one reopening on the other side.
--}}
<aside class="app-sidebar stats-sidebar" id="stats-sidebar" aria-label="Stats navigation">
    @foreach ($statsLinks as $link)
        <a id="stats-sidebar-link-{{ $link['id'] }}" href="{{ route($link['routes'][0]) }}"
            class="app-sidebar-link {{ request()->routeIs(...$link['routes']) ? 'active' : '' }}"
            data-stats-sidebar-close>
            {{ $link['label'] }}
        </a>

        @if ($link['id'] === 'all-games')
            <div class="app-sidebar-divider"></div>
        @endif
    @endforeach
</aside>
