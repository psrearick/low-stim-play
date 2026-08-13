@props(['livewire', 'players', 'currentPlayer', 'gameOver', 'readOnly' => false])

{{--
    A plain Blade component, not a nested Livewire component -- its markup
    is flattened straight into whichever Livewire component includes it
    (pages::yahtzee.play's own in-game view, plus profile.yahtzee's own
    read-only game view), so wire:click here still targets that enclosing
    component correctly. `$livewire` is passed in explicitly (rather than
    relying on `$this` propagating into a Blade include, which this
    codebase has never relied on) so isPickable()/categoryScore()/etc. stay
    simple, unambiguous method calls on whatever component instance is
    actually rendering this.

    Always shows every player's column, on every viewport (docs/
    concerns.md) -- this used to narrow to just one seat's own column on
    mobile via a `mySeatIndex` prop, with a separate "Scores" drawer
    elsewhere on the page covering everyone (see docs/DEVLOG.md's Yahtzee
    section). Removed in favor of relying on .card-wrap's own horizontal
    scroll (already built for this, see app.css) instead of hiding
    columns.
--}}
<div class="card-wrap">
    {{--
        table-layout: fixed with the table's own width left "auto" still
        let a longer name's column render wider than a short one's,
        despite every <col> below declaring the identical width --
        confirmed live. An explicit, definite total width (--player-count
        here) is what actually makes the browser divide it up by those
        <col> ratios instead of falling back to content-sensitive sizing.
    --}}
    <table class="scorecard" style="--player-count: {{ count($players) }};">
        <colgroup>
            <col class="category-col">
            @foreach ($players as $i => $player)
                <col class="player-col">
            @endforeach
        </colgroup>
        <thead>
            <tr>
                <th class="category"></th>
                @foreach ($players as $i => $player)
                    <th class="{{ ! $gameOver && $i === $currentPlayer ? 'active-player' : '' }}">{{ $player['name'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach (\App\Enums\YahtzeeCategory::upper() as $category)
                <tr wire:key="row-{{ $category->value }}">
                    <td class="category">{{ $category->label() }}</td>
                    @foreach ($players as $i => $player)
                        <td wire:key="cell-{{ $i }}-{{ $category->value }}"
                            @if (! $readOnly && $livewire->isPickable($i, $category->value)) wire:click="requestScore('{{ $category->value }}')" @endif
                            class="score-cell {{ ! $readOnly && $livewire->isPickable($i, $category->value) ? 'pickable' : '' }} {{ $livewire->isAvailable($i, $category->value) ? 'available' : '' }} {{ $player['scores'][$category->value] !== null ? 'filled' : '' }}">
                            {{ $livewire->categoryScore($i, $category->value) }}
                        </td>
                    @endforeach
                </tr>
            @endforeach

            <tr class="subtotal">
                <td class="category">Upper Subtotal</td>
                @foreach ($players as $i => $player)
                    <td data-role="upperSubtotal">{{ $livewire->upperSubtotalFor($i) }}</td>
                @endforeach
            </tr>

            <tr>
                <td class="category">Bonus (63+ = 35)</td>
                @foreach ($players as $i => $player)
                    <td data-role="upperBonus">{{ $livewire->upperBonusFor($i) }}</td>
                @endforeach
            </tr>

            @foreach (\App\Enums\YahtzeeCategory::lower() as $category)
                <tr wire:key="row-{{ $category->value }}">
                    <td class="category">{{ $category->label() }}</td>
                    @foreach ($players as $i => $player)
                        <td wire:key="cell-{{ $i }}-{{ $category->value }}"
                            @if (! $readOnly && $livewire->isPickable($i, $category->value)) wire:click="requestScore('{{ $category->value }}')" @endif
                            class="score-cell {{ ! $readOnly && $livewire->isPickable($i, $category->value) ? 'pickable' : '' }} {{ $livewire->isAvailable($i, $category->value) ? 'available' : '' }} {{ $player['scores'][$category->value] !== null ? 'filled' : '' }}">
                            {{ $livewire->categoryScore($i, $category->value) }}
                        </td>
                    @endforeach
                </tr>
            @endforeach

            <tr>
                <td class="category">Yacht Bonus</td>
                @foreach ($players as $i => $player)
                    <td data-role="yahtzeeBonus">
                        @if ($player['yahtzeeBonusCount'] > 0)
                            {{ str_repeat('✓', $player['yahtzeeBonusCount']) }} {{ $livewire->yahtzeeBonusPointsFor($i) }}
                        @endif
                    </td>
                @endforeach
            </tr>

            <tr class="total">
                <td class="category">Total</td>
                @foreach ($players as $i => $player)
                    <td data-role="total">{{ $livewire->totalScoreFor($i) }}</td>
                @endforeach
            </tr>
        </tbody>
    </table>
</div>
