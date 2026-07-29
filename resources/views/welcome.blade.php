@component('layouts.app')
    @slot('title')
        Low Stim Play
    @endslot

    <div class="heading">Low Stim Play</div>

    <div class="intro">
        <p>A small collection of quiet games. Low contrast, no motion, built to be gentle on sensitive eyes and heads. <a href="{{ route('about') }}">Read why</a>.</p>
    </div>

    <div class="game-grid">
        <div class="game-card">
            <div class="game-card-title">Boggle</div>
            <p class="game-card-desc">A shuffled 4&times;4 or 5&times;5 letter grid. Play it yourself, or put it on a shared screen and find words together as a group.</p>
            <div class="game-card-actions">
                <a href="{{ route('boggle.lobby') }}"><button type="button">Boggle</button></a>
                <a href="{{ route('boggle.group') }}"><button type="button">Boggle (Group Play)</button></a>
            </div>
        </div>
        <div class="game-card">
            <div class="game-card-title">Yahtzee</div>
            <p class="game-card-desc">A full scorecard for 1&ndash;8 players, or just the dice if you're keeping score by hand.</p>
            <div class="game-card-actions">
                <a href="{{ route('yahtzee.play') }}"><button type="button">Yahtzee</button></a>
                <a href="{{ route('yahtzee.roller') }}"><button type="button">Yahtzee (Dice Only)</button></a>
            </div>
        </div>
        <div class="game-card">
            <div class="game-card-title">Family Feud</div>
            <p class="game-card-desc">A single shared screen for family game night &mdash; an AI judges guesses server-side, so there's no hidden host view to manage.</p>
            <div class="game-card-actions">
                <a href="{{ route('family-feud.play') }}"><button type="button">Family Feud</button></a>
            </div>
        </div>
    </div>

    @guest
        <p class="home-account-links">
            <a href="{{ route('register') }}">Create an account</a> to track your games and stats, or
            <a href="{{ route('login') }}">sign in</a>.
        </p>
    @else
        <p class="home-account-links">
            <a href="{{ route('dashboard') }}">Your dashboard</a>
        </p>
    @endguest
@endcomponent
