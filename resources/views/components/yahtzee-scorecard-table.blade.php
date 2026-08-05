@props(['livewire', 'players', 'currentPlayer', 'gameOver', 'readOnly' => false, 'mySeatIndex' => null])

{{--
    A plain Blade component, not a nested Livewire component -- its markup
    is flattened straight into whichever Livewire component includes it
    (pages::yahtzee.play, for both the seated in-game view and the
    scoreboard drawer, plus profile.yahtzee's own read-only game view), so
    wire:click here still targets that enclosing component correctly.
    `$livewire` is passed in explicitly (rather than relying on `$this`
    propagating into a Blade include, which this codebase has never relied
    on) so isPickable()/categoryScore()/etc. stay simple, unambiguous method
    calls on whatever component instance is actually rendering this.

    `$mySeatIndex` marks every other player's column `not-mine` and flags
    the table `single-seat-mobile` -- CSS then hides those columns below
    the mobile breakpoint (see app.css), so a seated player's own phone
    shows just their own scores instead of a squeezed multi-column table
    already fully covered by the "Scores" drawer. Left null for the
    drawer's own copy of this table (it exists specifically to show
    *everyone*) and for a spectator (nothing is "mine" to narrow down to).
--}}
<div class="card-wrap">
    {{--
        Two things had to be true together for this table to render
        correctly, both confirmed the hard way (live, not assumed):
        1. table-layout: fixed with the table's own width left "auto"
           still let a longer name's column render wider than a short
           one's, despite every <col> below declaring the identical 10ch
           -- an explicit, definite total width (--player-count here) is
           what actually makes the browser divide it up by those <col>
           ratios instead of falling back to content-sensitive sizing.
        2. That total has to match however many columns are *actually
           visible*, which isn't always every player: single-seat-mobile
           (see its own comment below) hides every not-mine column via
           CSS alone, with no server-side knowledge of viewport width.
           Hiding just the *cells* left their <col>s still contributing
           their share of --player-count's total, reserving a dead gap
           where they used to be -- collapsing the <col> itself (not just
           the cells) is what actually lets the table shrink.
    --}}
    <table
        class="scorecard {{ $mySeatIndex !== null ? 'single-seat-mobile' : '' }}"
        style="--player-count: {{ count($players) }};"
    >
        <colgroup>
            <col class="category-col">
            @foreach ($players as $i => $player)
                <col class="player-col {{ $mySeatIndex !== null && $i !== $mySeatIndex ? 'not-mine' : '' }}">
            @endforeach
        </colgroup>
        <thead>
            <tr>
                <th class="category"></th>
                @foreach ($players as $i => $player)
                    <th class="{{ ! $gameOver && $i === $currentPlayer ? 'active-player' : '' }} {{ $mySeatIndex !== null && $i !== $mySeatIndex ? 'not-mine' : '' }}">{{ $player['name'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach (\App\Enums\YahtzeeCategory::upper() as $category)
                <tr wire:key="row-{{ $category->value }}">
                    <td class="category">{{ $category->label() }}</td>
                    @foreach ($players as $i => $player)
                        <td wire:key="cell-{{ $i }}-{{ $category->value }}"
                            @if (! $readOnly && $livewire->isPickable($i, $category->value)) wire:click="scoreCategory('{{ $category->value }}')" @endif
                            class="score-cell {{ ! $readOnly && $livewire->isPickable($i, $category->value) ? 'pickable' : '' }} {{ $player['scores'][$category->value] !== null ? 'filled' : '' }} {{ $mySeatIndex !== null && $i !== $mySeatIndex ? 'not-mine' : '' }}">
                            {{ $livewire->categoryScore($i, $category->value) }}
                        </td>
                    @endforeach
                </tr>
            @endforeach

            <tr class="subtotal">
                <td class="category">Upper Subtotal</td>
                @foreach ($players as $i => $player)
                    <td data-role="upperSubtotal" class="{{ $mySeatIndex !== null && $i !== $mySeatIndex ? 'not-mine' : '' }}">{{ $livewire->upperSubtotalFor($i) }}</td>
                @endforeach
            </tr>

            <tr>
                <td class="category">Bonus (63+ = 35)</td>
                @foreach ($players as $i => $player)
                    <td data-role="upperBonus" class="{{ $mySeatIndex !== null && $i !== $mySeatIndex ? 'not-mine' : '' }}">{{ $livewire->upperBonusFor($i) }}</td>
                @endforeach
            </tr>

            @foreach (\App\Enums\YahtzeeCategory::lower() as $category)
                <tr wire:key="row-{{ $category->value }}">
                    <td class="category">{{ $category->label() }}</td>
                    @foreach ($players as $i => $player)
                        <td wire:key="cell-{{ $i }}-{{ $category->value }}"
                            @if (! $readOnly && $livewire->isPickable($i, $category->value)) wire:click="scoreCategory('{{ $category->value }}')" @endif
                            class="score-cell {{ ! $readOnly && $livewire->isPickable($i, $category->value) ? 'pickable' : '' }} {{ $player['scores'][$category->value] !== null ? 'filled' : '' }} {{ $mySeatIndex !== null && $i !== $mySeatIndex ? 'not-mine' : '' }}">
                            {{ $livewire->categoryScore($i, $category->value) }}
                        </td>
                    @endforeach
                </tr>
            @endforeach

            <tr>
                <td class="category">Yacht Bonus</td>
                @foreach ($players as $i => $player)
                    <td data-role="yahtzeeBonus" class="{{ $mySeatIndex !== null && $i !== $mySeatIndex ? 'not-mine' : '' }}">
                        @if ($player['yahtzeeBonusCount'] > 0)
                            {{ str_repeat('✓', $player['yahtzeeBonusCount']) }} {{ $livewire->yahtzeeBonusPointsFor($i) }}
                        @endif
                    </td>
                @endforeach
            </tr>

            <tr class="total">
                <td class="category">Total</td>
                @foreach ($players as $i => $player)
                    <td data-role="total" class="{{ $mySeatIndex !== null && $i !== $mySeatIndex ? 'not-mine' : '' }}">{{ $livewire->totalScoreFor($i) }}</td>
                @endforeach
            </tr>
        </tbody>
    </table>
</div>
