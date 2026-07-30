<?php

use App\Enums\GameMode;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Enums\YahtzeeCategory;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Services\Yahtzee\DiceRoller;
use App\Services\Yahtzee\GameEngine;
use Illuminate\Support\Facades\Auth;
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
     * Which local player, if any, is the authenticated account holder --
     * null means "not attributing this game to my account." Only offered
     * when logged in; drives user_id vs guest_name in endGame().
     */
    public ?int $meIndex = null;

    /**
     * Snapshots taken right before each mutating action (roll,
     * scoreCategory), so undo() can step back exactly one such action --
     * mirrors legacy's pushHistory()/undo() (legacy/yahtzee.html:872-880,
     * 580-596,956-967). Deliberately not resumable once the game is over:
     * endGame() already persists a GameSession/GameParticipant the moment
     * the last category is scored, and unwinding that DB write is out of
     * scope here.
     *
     * @var list<array{dice: list<int>, kept: list<bool>, rollsLeft: int, players: array, currentPlayer: int}>
     */
    public array $history = [];

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

        if ($this->meIndex !== null && $this->meIndex >= $this->playerCount) {
            $this->meIndex = null;
        }
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
        $this->history = [];
        $this->startTurn();
    }

    public function roll(DiceRoller $diceRoller): void
    {
        if ($this->gameOver || $this->rollsLeft <= 0) {
            return;
        }

        $this->pushHistory();

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

        $this->pushHistory();

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

    private function pushHistory(): void
    {
        $this->history[] = [
            'dice' => $this->dice,
            'kept' => $this->kept,
            'rollsLeft' => $this->rollsLeft,
            'players' => $this->players,
            'currentPlayer' => $this->currentPlayer,
        ];
    }

    public function undo(): void
    {
        if ($this->gameOver || $this->history === []) {
            return;
        }

        $snapshot = array_pop($this->history);

        $this->dice = $snapshot['dice'];
        $this->kept = $snapshot['kept'];
        $this->rollsLeft = $snapshot['rollsLeft'];
        $this->players = $snapshot['players'];
        $this->currentPlayer = $snapshot['currentPlayer'];
        $this->message = null;
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

            // Pass-and-play has no way to know which local player is the
            // account holder in general (see docs/CHANGELOG.md) -- $meIndex
            // is the host explicitly declaring it during setup, the same
            // way Boggle's own solo games attribute user_id vs guest_name.
            $isMe = $i === $this->meIndex && Auth::check();

            GameParticipant::create([
                'game_session_id' => $session->id,
                'user_id' => $isMe ? Auth::id() : null,
                'guest_name' => $isMe ? null : $player['name'],
                'score' => $total,
                'result' => $result,
                'stats' => ['yahtzee_bonus_count' => $player['yahtzeeBonusCount']],
            ]);
        }

        $winnerName = collect($this->players)
            ->filter(fn ($player, $i) => $totals[$i] === $topScore)
            ->pluck('name')
            ->first();

        $this->message = $winners > 1
            ? "Game Over — It's a tie at {$topScore}!"
            : "Game Over — {$winnerName} wins with {$topScore}!";
    }

    public function newGame(): void
    {
        $this->started = false;
        $this->gameOver = false;
        $this->players = [];
        $this->message = null;
        $this->history = [];
        $this->meIndex = null;
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

    public function getStatusTextProperty(): string
    {
        if ($this->gameOver) {
            return $this->message ?? '';
        }

        $name = $this->players[$this->currentPlayer]['name'];
        $rollText = $this->dice === []
            ? 'Press Roll to Start'
            : 'Roll '.(3 - $this->rollsLeft).' of 3';

        return "{$name}'s turn — {$rollText}";
    }
};
?>

<x-slot:title>Yahtzee — Low Stim Play</x-slot>
<x-slot:description>A full Yahtzee scorecard for 1-8 players, including the upper-section bonus and Joker rules, in a calm, low-contrast design.</x-slot>
<x-slot:bodyClass>yahtzee-play{{ $started ? ' in-game' : '' }}</x-slot:bodyClass>
<x-slot:fullscreenToggle>1</x-slot:fullscreenToggle>
<x-slot:rulesTitle>Yahtzee Rules</x-slot:rulesTitle>
<x-slot:rules>
    <h3>How to Use This Page</h3>
    <ul>
        <li>Set the number of players (1&ndash;8) and optional names, then press Start Game.</li>
        <li>On your turn, press Roll (up to 3 times); tap a die to hold it so it won't reroll.</li>
        <li>Once you've rolled at least once, tap a highlighted cell in your column to score that category &mdash; the number shown is exactly what you'd get for picking it right now.</li>
        <li>Once every player has filled every category, the game ends and the winner is shown.</li>
    </ul>

    <h3>Full Rules</h3>
    <ul>
        <li>Each turn, roll the five dice up to three times total, keeping any dice you like between rolls — you don't have to use all three.</li>
        <li>After rolling at least once, choose one open category to score for that turn.</li>
        <li>Ones through Sixes: scores the sum of just the dice matching that number (e.g. three 4s in the Fours box scores 12).</li>
        <li>If your six Upper Section boxes (Ones&ndash;Sixes) add up to 63 or more, you get a 35-point bonus.</li>
        <li>3 of a Kind / 4 of a Kind: if at least three (or four) dice match, scores the sum of all five dice; otherwise 0.</li>
        <li>Full House: scores 25 for three of one number and two of another.</li>
        <li>Small Straight: scores 30 for four sequential numbers (e.g. 2-3-4-5).</li>
        <li>Large Straight: scores 40 for five sequential numbers (1-5 or 2-6).</li>
        <li>Yahtzee: scores 50 for five of a kind.</li>
        <li>Chance: always scores the sum of all five dice, whatever you rolled.</li>
        <li>Yahtzee Bonus: once your Yahtzee box shows 50, every additional five-of-a-kind you roll earns a 100-point bonus chip, on top of whatever category you score that turn.</li>
        <li>Joker rule: once your Yahtzee box has been filled at all (scored 50 or forced to 0), a later five-of-a-kind lets Full House / Small Straight / Large Straight score their fixed value (25 / 30 / 40) automatically &mdash; but only if the Upper Section box matching the number you rolled is already filled. If it's still open, those three categories score 0 for that roll instead, so scoring the matching Upper Section box (or Chance) is usually the better move.</li>
        <li>The game ends once every player has filled every category; whoever has the highest total wins (ties are possible).</li>
    </ul>
</x-slot:rules>
<div>
    {{--
        Teleported (see layouts/app.blade.php's #game-actions anchor) rather
        than a layout $barRight slot -- this button only appears once
        $started flips true, which happens after the page's first render,
        and a layout slot only ever captures its content once, at that
        first render.
    --}}
    @if ($started)
        @teleport('#game-actions')
            <button type="button" wire:click="newGame">New Game</button>
        @endteleport
    @endif

    @unless ($started)
        <div class="setup" id="setup">
            <div class="heading">Yahtzee</div>

            <div class="setup-row">
                <label id="playerCountLabel">Number of Players</label>
                <div class="stepper" role="group" aria-labelledby="playerCountLabel">
                    <button type="button" wire:click="setPlayerCount({{ $playerCount - 1 }})" @disabled($playerCount <= self::MIN_PLAYERS) aria-label="Fewer players">-</button>
                    <div class="stepper-value" aria-live="polite">{{ $playerCount }}</div>
                    <button type="button" wire:click="setPlayerCount({{ $playerCount + 1 }})" @disabled($playerCount >= self::MAX_PLAYERS) aria-label="More players">+</button>
                </div>
            </div>

            <div class="player-names">
                @for ($i = 0; $i < $playerCount; $i++)
                    <input type="text" wire:model="playerNames.{{ $i }}" maxlength="16" placeholder="Player {{ $i + 1 }} name">
                @endfor
            </div>

            @auth
                <div class="setup-row">
                    <div class="label">Which player is you?</div>
                    <div class="option-group">
                        <button type="button" class="option {{ $meIndex === null ? 'active' : '' }}" wire:click="$set('meIndex', null)">Not me</button>
                        @for ($i = 0; $i < $playerCount; $i++)
                            <button type="button" class="option {{ $meIndex === $i ? 'active' : '' }}" wire:click="$set('meIndex', {{ $i }})">
                                Player {{ $i + 1 }}
                            </button>
                        @endfor
                    </div>
                </div>
            @endauth

            <button type="button" wire:click="startGame">Start Game</button>
        </div>
    @else
        <div id="yahtzee-game" class="yahtzee-play">
            <div class="status">{{ $this->statusText }}</div>

            <div class="dice-area">
                <div class="row">
                    @for ($i = 0; $i < self::DICE_COUNT; $i++)
                        <div class="die {{ ($kept[$i] ?? false) ? 'kept' : '' }}" wire:click="toggleKeep({{ $i }})">
                            <span class="number">{{ $dice[$i] ?? '' }}</span>
                        </div>
                    @endfor
                </div>

                <div class="controls">
                    <button type="button" id="rollBtn" wire:click="roll" @disabled($gameOver || $rollsLeft <= 0)>Roll</button>
                    <button type="button" id="undoBtn" wire:click="undo" @disabled($gameOver || $history === [])>Undo</button>
                </div>
            </div>

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
                        @foreach (YahtzeeCategory::upper() as $category)
                            <tr wire:key="row-{{ $category->value }}">
                                <td class="category">{{ $category->label() }}</td>
                                @foreach ($players as $i => $player)
                                    <td wire:key="cell-{{ $i }}-{{ $category->value }}"
                                        @if ($this->isPickable($i, $category->value)) wire:click="scoreCategory('{{ $category->value }}')" @endif
                                        class="score-cell {{ $this->isPickable($i, $category->value) ? 'pickable' : '' }} {{ $player['scores'][$category->value] !== null ? 'filled' : '' }}">
                                        {{ $this->categoryScore($i, $category->value) }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach

                        <tr class="subtotal">
                            <td class="category">Upper Subtotal</td>
                            @foreach ($players as $i => $player)
                                <td data-role="upperSubtotal">{{ $this->upperSubtotalFor($i) }}</td>
                            @endforeach
                        </tr>

                        <tr>
                            <td class="category">Bonus (63+ = 35)</td>
                            @foreach ($players as $i => $player)
                                <td data-role="upperBonus">{{ $this->upperBonusFor($i) }}</td>
                            @endforeach
                        </tr>

                        @foreach (YahtzeeCategory::lower() as $category)
                            <tr wire:key="row-{{ $category->value }}">
                                <td class="category">{{ $category->label() }}</td>
                                @foreach ($players as $i => $player)
                                    <td wire:key="cell-{{ $i }}-{{ $category->value }}"
                                        @if ($this->isPickable($i, $category->value)) wire:click="scoreCategory('{{ $category->value }}')" @endif
                                        class="score-cell {{ $this->isPickable($i, $category->value) ? 'pickable' : '' }} {{ $player['scores'][$category->value] !== null ? 'filled' : '' }}">
                                        {{ $this->categoryScore($i, $category->value) }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach

                        <tr>
                            <td class="category">Yahtzee Bonus</td>
                            @foreach ($players as $i => $player)
                                <td data-role="yahtzeeBonus">
                                    @if ($player['yahtzeeBonusCount'] > 0)
                                        {{ str_repeat('✓', $player['yahtzeeBonusCount']) }} {{ $this->yahtzeeBonusPointsFor($i) }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>

                        <tr class="total">
                            <td class="category">Total</td>
                            @foreach ($players as $i => $player)
                                <td data-role="total">{{ $this->totalScoreFor($i) }}</td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    @endunless
</div>
