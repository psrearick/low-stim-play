@component('layouts.app')
    @slot('title')
        Low Stim Play
    @endslot

    @slot('manyButtons')
        1
    @endslot

    @slot('barRight')
        @guest
            <a href="{{ route('login') }}"><button type="button">Sign In</button></a>
            <a href="{{ route('register') }}"><button type="button">Create an Account</button></a>
        @else
            <a href="{{ route('dashboard') }}"><button type="button">Dashboard</button></a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">Log Out</button>
            </form>
        @endguest
    @endslot

    <div class="heading">Low Stim Play</div>

    <div class="intro">
        <p>A small collection of quiet games. Low contrast, no motion, built to be gentle on sensitive eyes and heads. <a href="{{ route('about') }}">Read why</a>.</p>
    </div>

    <div class="game-grid">
        <div class="game-card">
            <div class="game-card-title">Word Grid</div>
            <p class="game-card-desc">A shuffled 4&times;4 or 5&times;5 letter grid. Play it yourself, or put it on a shared screen and find words together as a group.</p>
            <div class="game-card-actions">
                <a href="{{ route('boggle.lobby') }}"><button type="button">Word Grid</button></a>
                <a href="{{ route('boggle.group') }}"><button type="button">Word Grid (Group Play)</button></a>
            </div>
        </div>
        <div class="game-card">
            <div class="game-card-title">Yacht</div>
            <p class="game-card-desc">A full scorecard for 1&ndash;8 players, or just the dice if you're keeping score by hand.</p>
            <div class="game-card-actions">
                <a href="{{ route('yahtzee.play') }}"><button type="button">Yacht</button></a>
                <a href="{{ route('yahtzee.roller') }}"><button type="button">Yacht (Dice Only)</button></a>
            </div>
        </div>
        <div class="game-card">
            <div class="game-card-title">Survey Says</div>
            <p class="game-card-desc">Two teams guess the most popular answers to survey-style questions. Points for each match; three strikes hands the other team a shot to steal the pot.</p>
            <div class="game-card-actions">
                <a href="{{ route('family-feud.play') }}"><button type="button">Survey Says</button></a>
            </div>
        </div>
        <div class="game-card">
            <div class="game-card-title">Farkle</div>
            <p class="game-card-desc">Roll six dice, set aside whatever scores, and decide each turn whether to bank your points or risk them on another roll for 1&ndash;8 players.</p>
            <div class="game-card-actions">
                <a href="{{ route('farkle.play') }}"><button type="button">Farkle</button></a>
            </div>
        </div>
    </div>

@endcomponent
