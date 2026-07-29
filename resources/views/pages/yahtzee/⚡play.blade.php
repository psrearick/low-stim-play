<?php

use App\Enums\GameMode;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Enums\YahtzeeCategory;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Services\Yahtzee\DiceRoller;
use App\Services\Yahtzee\GameEngine;
use Livewire\Component;

new class extends Component
{
    public const MIN_PLAYERS = 1;

    public const MAX_PLAYERS = 8;

    public const DICE_COUNT = 5;

    public int $playerCount = 2;

    /**
     * @var list<string>
     */
    public array $playerNames = ['', ''];

    public bool $started = false;

    public bool $gameOver = false;

    /**
     * @var list<array{name: string, scores: array<string, int|null>, yahtzeeBonusCount: int}>
     */
    public array $players = [];

    public int $currentPlayer = 0;

    /**
     * @var list<int>
     */
    public array $dice = [];

    /**
     * @var list<bool>
     */
    public array $kept = [];

    public int $rollsLeft = 3;

    public ?string $message = null;

    /**
     * Not a Livewire-synced property (a service instance can't be), so
     * it's re-resolved via `boot()` on every request instead of `mount()`
     * (which only runs once) -- this is also what lets Blade call methods
     * like `categoryScore()` directly, since Livewire's automatic method
     * dependency injection only applies to actions dispatched via
     * `wire:click` etc., not to plain method calls from within a render.
     */
    private GameEngine $engine;

    public function boot(GameEngine $engine): void
    {
        $this->engine = $engine;
    }

    public function setPlayerCount(int $count): void
    {
        $this->playerCount = max(self::MIN_PLAYERS, min(self::MAX_PLAYERS, $count));

        $this->playerNames = array_pad(
            array_slice($this->playerNames, 0, $this->playerCount),
            $this->playerCount,
            '',
        );
    }

    public function startGame(): void
    {
        $this->players = array_map(
            fn (int $i) => [
                'name' => trim($this->playerNames[$i] ?? '') !== '' ? trim($this->playerNames[$i]) : 'Player '.($i + 1),
                'scores' => array_fill_keys(array_map(fn ($c) => $c->value, YahtzeeCategory::all()), null),
                'yahtzeeBonusCount' => 0,
            ],
            range(0, $this->playerCount - 1),
        );

        $this->currentPlayer = 0;
        $this->gameOver = false;
        $this->started = true;
        $this->message = null;
        $this->startTurn();
    }

    public function roll(DiceRoller $diceRoller): void
    {
        if ($this->gameOver || $this->rollsLeft <= 0) {
            return;
        }

        if ($this->dice === []) {
            $this->dice = $diceRoller->initial();
            $this->kept = array_fill(0, self::DICE_COUNT, false);
        } else {
            $this->dice = $diceRoller->reroll($this->dice, $this->kept);
        }

        $this->rollsLeft--;
    }

    public function toggleKeep(int $index): void
    {
        if ($this->gameOver || $this->dice === []) {
            return;
        }

        $this->kept[$index] = ! $this->kept[$index];
    }

    public function scoreCategory(string $categoryValue): void
    {
        if (! $this->isPickable($this->currentPlayer, $categoryValue)) {
            return;
        }

        $player = $this->players[$this->currentPlayer];
        $category = YahtzeeCategory::from($categoryValue);
        $result = $this->engine->scoreCategory($category, $this->dice, $player['scores']);

        $player['scores'][$categoryValue] = $result['score'];

        if ($result['yahtzeeBonusAwarded']) {
            $player['yahtzeeBonusCount']++;
        }

        $this->players[$this->currentPlayer] = $player;

        $this->advanceTurn();
    }

    private function advanceTurn(): void
    {
        $everyoneFilled = collect($this->players)->every(
            fn (array $player) => $this->engine->allCategoriesFilled($player['scores']),
        );

        if ($everyoneFilled) {
            $this->endGame();

            return;
        }

        $this->currentPlayer = ($this->currentPlayer + 1) % count($this->players);
        $this->startTurn();
    }

    private function startTurn(): void
    {
        $this->dice = [];
        $this->kept = [];
        $this->rollsLeft = 3;
    }

    private function endGame(): void
    {
        $this->gameOver = true;

        $totals = collect($this->players)->map(
            fn (array $player) => $this->engine->totalScore($player['scores'], $player['yahtzeeBonusCount']),
        );

        $topScore = $totals->max();
        $winners = $totals->filter(fn (int $total) => $total === $topScore)->count();

        $session = GameSession::create([
            'type' => GameType::Yahtzee,
            'mode' => GameMode::Local,
            'settings' => ['player_count' => count($this->players)],
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        foreach ($this->players as $i => $player) {
            $total = $totals[$i];

            $result = match (true) {
                $total !== $topScore => GameResult::Loss,
                $winners > 1 => GameResult::Draw,
                default => GameResult::Win,
            };

            GameParticipant::create([
                'game_session_id' => $session->id,
                'guest_name' => $player['name'],
                'score' => $total,
                'result' => $result,
                'stats' => ['yahtzee_bonus_count' => $player['yahtzeeBonusCount']],
            ]);
        }

        $winnerNames = collect($this->players)
            ->filter(fn ($player, $i) => $totals[$i] === $topScore)
            ->pluck('name')
            ->implode(' & ');

        $this->message = $winners > 1
            ? "It's a tie at {$topScore}! ({$winnerNames})"
            : "{$winnerNames} wins with {$topScore}!";
    }

    public function newGame(): void
    {
        $this->started = false;
        $this->gameOver = false;
        $this->players = [];
        $this->message = null;
    }

    public function categoryScore(int $playerIndex, string $categoryValue): ?int
    {
        $player = $this->players[$playerIndex];
        $scored = $player['scores'][$categoryValue];

        if ($scored !== null) {
            return $scored;
        }

        if (! $this->isPickable($playerIndex, $categoryValue)) {
            return null;
        }

        $category = YahtzeeCategory::from($categoryValue);

        return $this->engine->scoreCategory($category, $this->dice, $player['scores'])['score'];
    }

    public function isPickable(int $playerIndex, string $categoryValue): bool
    {
        return ! $this->gameOver
            && $playerIndex === $this->currentPlayer
            && count($this->dice) === self::DICE_COUNT
            && $this->players[$playerIndex]['scores'][$categoryValue] === null;
    }

    public function upperSubtotalFor(int $playerIndex): int
    {
        return $this->engine->upperSubtotal($this->players[$playerIndex]['scores']);
    }

    public function upperBonusFor(int $playerIndex): int
    {
        return $this->engine->upperBonus($this->players[$playerIndex]['scores']);
    }

    public function yahtzeeBonusPointsFor(int $playerIndex): int
    {
        return $this->engine->yahtzeeBonusPoints($this->players[$playerIndex]['yahtzeeBonusCount']);
    }

    public function totalScoreFor(int $playerIndex): int
    {
        $player = $this->players[$playerIndex];

        return $this->engine->totalScore($player['scores'], $player['yahtzeeBonusCount']);
    }
};
?>

<div class="flex flex-col items-center gap-6 w-full max-w-5xl">
    @if (! $started)
        <div class="flex flex-col items-center gap-5 w-full max-w-md bg-surface border-2 border-surface-border rounded-lg p-8">
            <h1 class="text-accent font-bold tracking-wide text-2xl">Yahtzee</h1>

            <div class="flex items-center justify-between w-full">
                <p class="text-sm text-text/80 font-bold">Number of players</p>
                <div class="flex items-center gap-3">
                    <button type="button" wire:click="setPlayerCount({{ $playerCount - 1 }})" @disabled($playerCount <= self::MIN_PLAYERS)
                        class="rounded w-9 h-9 font-bold bg-surface border-2 border-surface-border text-text disabled:opacity-40">
                        &minus;
                    </button>
                    <span class="min-w-[2ch] text-center font-bold text-text">{{ $playerCount }}</span>
                    <button type="button" wire:click="setPlayerCount({{ $playerCount + 1 }})" @disabled($playerCount >= self::MAX_PLAYERS)
                        class="rounded w-9 h-9 font-bold bg-surface border-2 border-surface-border text-text disabled:opacity-40">
                        +
                    </button>
                </div>
            </div>

            <div class="flex flex-col gap-2 w-full">
                @for ($i = 0; $i < $playerCount; $i++)
                    <input type="text" wire:model="playerNames.{{ $i }}" maxlength="16" placeholder="Player {{ $i + 1 }} name"
                        class="w-full rounded border-2 border-surface-border bg-bg text-text px-3 py-2">
                @endfor
            </div>

            <button type="button" wire:click="startGame" class="w-full rounded px-6 py-2 font-bold tracking-wide bg-accent text-bg">
                Start game
            </button>
        </div>
    @else
        <div class="flex items-center justify-between w-full max-w-md text-sm text-text/80">
            <a href="{{ route('yahtzee.play') }}" class="hover:text-text">&larr; Home</a>
            <span class="text-accent font-bold">
                @if ($gameOver)
                    {{ $message }}
                @else
                    {{ $players[$currentPlayer]['name'] }}'s turn
                @endif
            </span>
        </div>

        @unless ($gameOver)
            <div class="flex flex-wrap items-center justify-center gap-4">
                @for ($i = 0; $i < self::DICE_COUNT; $i++)
                    <button type="button" wire:click="toggleKeep({{ $i }})"
                        class="w-16 h-16 sm:w-20 sm:h-20 rounded border-2 flex items-center justify-center font-bold text-3xl
                            {{ ($kept[$i] ?? false) ? 'bg-accent text-bg border-accent' : 'bg-surface text-text border-surface-border' }}">
                        {{ $dice[$i] ?? '' }}
                    </button>
                @endfor
            </div>

            <button type="button" wire:click="roll" @disabled($rollsLeft <= 0)
                class="rounded px-8 py-3 font-bold tracking-wide bg-accent text-bg disabled:opacity-40">
                {{ $dice === [] ? 'Roll' : "Roll ({$rollsLeft} left)" }}
            </button>
        @endunless

        <div class="w-full overflow-x-auto">
            <table class="w-full text-sm text-text border-collapse">
                <thead>
                    <tr>
                        <th class="text-left p-2 bg-surface border border-surface-border"></th>
                        @foreach ($players as $i => $player)
                            <th class="p-2 bg-surface border border-surface-border {{ ! $gameOver && $i === $currentPlayer ? 'text-accent' : '' }}">
                                {{ $player['name'] }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach (YahtzeeCategory::upper() as $category)
                        <tr wire:key="row-{{ $category->value }}">
                            <td class="text-left font-bold p-2 bg-surface border border-surface-border">{{ $category->label() }}</td>
                            @foreach ($players as $i => $player)
                                <td wire:key="cell-{{ $i }}-{{ $category->value }}"
                                    @if ($this->isPickable($i, $category->value)) wire:click="scoreCategory('{{ $category->value }}')" @endif
                                    class="p-2 border border-surface-border text-center
                                        {{ $this->isPickable($i, $category->value) ? 'cursor-pointer bg-accent/15 text-accent' : 'bg-bg' }}
                                        {{ $player['scores'][$category->value] !== null ? 'font-bold' : '' }}">
                                    {{ $this->categoryScore($i, $category->value) }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach

                    <tr class="font-bold">
                        <td class="text-left p-2 bg-surface-border border border-surface-border">Upper Subtotal</td>
                        @foreach ($players as $i => $player)
                            <td class="p-2 border border-surface-border text-center bg-surface-border">{{ $this->upperSubtotalFor($i) }}</td>
                        @endforeach
                    </tr>

                    <tr>
                        <td class="text-left p-2 bg-surface border border-surface-border">Bonus (63+ = 35)</td>
                        @foreach ($players as $i => $player)
                            <td class="p-2 border border-surface-border text-center bg-bg">{{ $this->upperBonusFor($i) }}</td>
                        @endforeach
                    </tr>

                    @foreach (YahtzeeCategory::lower() as $category)
                        <tr wire:key="row-{{ $category->value }}">
                            <td class="text-left font-bold p-2 bg-surface border border-surface-border">{{ $category->label() }}</td>
                            @foreach ($players as $i => $player)
                                <td wire:key="cell-{{ $i }}-{{ $category->value }}"
                                    @if ($this->isPickable($i, $category->value)) wire:click="scoreCategory('{{ $category->value }}')" @endif
                                    class="p-2 border border-surface-border text-center
                                        {{ $this->isPickable($i, $category->value) ? 'cursor-pointer bg-accent/15 text-accent' : 'bg-bg' }}
                                        {{ $player['scores'][$category->value] !== null ? 'font-bold' : '' }}">
                                    {{ $this->categoryScore($i, $category->value) }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach

                    <tr>
                        <td class="text-left p-2 bg-surface border border-surface-border">Yahtzee Bonus</td>
                        @foreach ($players as $i => $player)
                            <td class="p-2 border border-surface-border text-center bg-bg">
                                @if ($player['yahtzeeBonusCount'] > 0)
                                    {{ str_repeat('✓', $player['yahtzeeBonusCount']) }} {{ $this->yahtzeeBonusPointsFor($i) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>

                    <tr class="font-bold">
                        <td class="text-left p-2 bg-surface-border border border-surface-border">Total</td>
                        @foreach ($players as $i => $player)
                            <td class="p-2 border border-surface-border text-center bg-surface-border">{{ $this->totalScoreFor($i) }}</td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        </div>

        @if ($gameOver)
            <button type="button" wire:click="newGame" class="w-full max-w-md rounded px-6 py-2 font-bold tracking-wide bg-accent text-bg">
                New game
            </button>
        @endif
    @endif
</div>
