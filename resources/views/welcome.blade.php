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
            <div class="game-card-title">Word Grid</div>
            <p class="game-card-desc">A shuffled 4&times;4 or 5&times;5 letter grid. Play it yourself, or put it on a shared screen and find words together as a group.</p>
            <div class="game-card-actions">
                <a href="{{ route('boggle.lobby') }}"><button type="button">Word Grid</button></a>
                <a href="{{ route('boggle.group') }}"><button type="button">Word Grid (Group Play)</button></a>
                <livewire:game-card-resume :type="\App\Enums\GameType::Boggle" wire:key="resume-boggle" />
            </div>
        </div>
        <div class="game-card">
            <div class="game-card-title">Yacht</div>
            <p class="game-card-desc">A full scorecard for 1&ndash;8 players, or just the dice if you're keeping score by hand.</p>
            <div class="game-card-actions">
                <a href="{{ route('yahtzee.play') }}"><button type="button">Yacht</button></a>
                <a href="{{ route('yahtzee.roller') }}"><button type="button">Yacht (Dice Only)</button></a>
                <livewire:game-card-resume :type="\App\Enums\GameType::Yahtzee" wire:key="resume-yahtzee" />
            </div>
        </div>
        <div class="game-card">
            <div class="game-card-title">Survey Says</div>
            <p class="game-card-desc">Two teams guess the most popular answers to survey-style questions. Points for each match; three strikes hands the other team a shot to steal the pot.</p>
            <div class="game-card-actions">
                <a href="{{ route('family-feud.play') }}"><button type="button">Survey Says</button></a>
                <livewire:game-card-resume :type="\App\Enums\GameType::FamilyFeud" wire:key="resume-family-feud" />
            </div>
        </div>
        <div class="game-card">
            <div class="game-card-title">10,000</div>
            <p class="game-card-desc">Roll six dice, set aside whatever scores, and decide each turn whether to bank your points or risk them on another roll for 1&ndash;8 players.</p>
            <div class="game-card-actions">
                <a href="{{ route('farkle.play') }}"><button type="button">10,000</button></a>
                <livewire:game-card-resume :type="\App\Enums\GameType::Farkle" wire:key="resume-farkle" />
            </div>
        </div>
        <div class="game-card">
            <div class="game-card-title">Best Match</div>
            <p class="game-card-desc">A rotating judge reveals a prompt card; everyone else secretly submits their best-matching answer card for 3&ndash;8 players. Online rooms only, since a private hand is the whole point.</p>
            <div class="game-card-actions">
                <a href="{{ route('apple-match.play') }}"><button type="button">Best Match</button></a>
                <livewire:game-card-resume :type="\App\Enums\GameType::AppleMatch" wire:key="resume-apple-match" />
            </div>
        </div>
        <div class="game-card">
            <div class="game-card-title">Punchline</div>
            <p class="game-card-desc">Write funny answers to prompts, then vote on the funniest &mdash; each matchup's points split by vote share, escalating round to round, for 3&ndash;8 players. Online rooms only, since everyone writes privately.</p>
            <div class="game-card-actions">
                <a href="{{ route('punchline.play') }}"><button type="button">Punchline</button></a>
                <livewire:game-card-resume :type="\App\Enums\GameType::Punchline" wire:key="resume-punchline" />
            </div>
        </div>
        <div class="game-card">
            <div class="game-card-title">Clear the Deck (Tens)</div>
            <p class="game-card-desc">A shared pile, a private hand, and table cards hidden even from you until you play them, for 3&ndash;10 players. Match or beat the pile, clear it with four of a kind, and try to be first to empty your hand. Online rooms only.</p>
            <div class="game-card-actions">
                <a href="{{ route('clear-the-deck.play') }}"><button type="button">Clear the Deck (Tens)</button></a>
                <livewire:game-card-resume :type="\App\Enums\GameType::ClearTheDeck" wire:key="resume-clear-the-deck" />
            </div>
        </div>
        <div class="game-card">
            <div class="game-card-title">Word Blocks</div>
            <p class="game-card-desc">Combine 2&ndash;4 letter tiles into words. Five hidden four-tile "Quads" earn a bonus if you find them all &mdash; solo, untimed.</p>
            <div class="game-card-actions">
                <a href="{{ route('quartiles.play') }}"><button type="button">Word Blocks</button></a>
            </div>
        </div>
        <div class="game-card">
            <div class="game-card-title">Five Letters</div>
            <p class="game-card-desc">Guess the secret 5-letter word in 6 tries. Each guess shows which letters are right, in the wrong spot, or not in the word at all &mdash; solo, untimed.</p>
            <div class="game-card-actions">
                <a href="{{ route('wordle.play') }}"><button type="button">Five Letters</button></a>
            </div>
        </div>
        <div class="game-card">
            <div class="game-card-title">Word Hive</div>
            <p class="game-card-desc">Spell words of 4 or more letters using 7 given letters, every word must include the center letter. Longer words and a pangram (using all 7) score more &mdash; solo, untimed.</p>
            <div class="game-card-actions">
                <a href="{{ route('spelling-bee.play') }}"><button type="button">Word Hive</button></a>
            </div>
        </div>
    </div>

@endcomponent
