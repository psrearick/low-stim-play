@props(['livewire', 'players', 'currentPlayer', 'gameOver', 'readOnly' => false])

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
--}}
<div class="card-wrap">
    <table class="scorecard">
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
                            @if (! $readOnly && $livewire->isPickable($i, $category->value)) wire:click="scoreCategory('{{ $category->value }}')" @endif
                            class="score-cell {{ ! $readOnly && $livewire->isPickable($i, $category->value) ? 'pickable' : '' }} {{ $player['scores'][$category->value] !== null ? 'filled' : '' }}">
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
                            @if (! $readOnly && $livewire->isPickable($i, $category->value)) wire:click="scoreCategory('{{ $category->value }}')" @endif
                            class="score-cell {{ ! $readOnly && $livewire->isPickable($i, $category->value) ? 'pickable' : '' }} {{ $player['scores'][$category->value] !== null ? 'filled' : '' }}">
                            {{ $livewire->categoryScore($i, $category->value) }}
                        </td>
                    @endforeach
                </tr>
            @endforeach

            <tr>
                <td class="category">Yahtzee Bonus</td>
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
