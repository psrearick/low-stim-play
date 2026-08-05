<?php

use App\Enums\GameMode;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Enums\YahtzeeCategory;
use App\Events\RoomGameStateUpdated;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use App\Services\Yahtzee\DiceRoller;
use App\Services\Yahtzee\GameEngine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
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

    public string $joinID = '';

    public string $guestName = '';

    public ?string $joinError = null;

    /**
     * The online room this play-through belongs to, if any -- mirrors
     * pages::boggle.play's own `$room`. When set, `$players`/`$dice`/
     * `$kept`/`$rollsLeft`/`$currentPlayer`/`$gameOver` are all hydrated
     * from the room's shared GameSession (see mount()) instead of running
     * local setup, every mutating action is gated to whichever seat's turn
     * it actually is, and every successful mutation persists + broadcasts
     * the new state so every other seat/spectator stays in sync.
     */
    #[Url]
    public ?string $room = null;

    public ?int $gameSessionId = null;

    /**
     * This connection's seat in the online room, or null -- null means
     * either purely local play, or an online room where this account
     * doesn't occupy a seat (spectator/host view: dice are still shown
     * live, but no clicking a die to keep it and no scoring).
     */
    public ?int $mySeatIndex = null;

    /**
     * A seated player's own explicit choice to view this device read-only
     * anyway -- for the case docs/TODO.md called out directly: the same
     * account logged in on two devices at once, one hosting/casting (this
     * one, with spectate=1) and one actually playing. Purely a per-device
     * URL flag, not account-wide state -- otherwise switching it on this
     * device would also switch it on the one they're actually playing
     * from. Ignored entirely for local pass-and-play.
     */
    #[Url]
    public bool $spectate = false;

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

    public function mount(): void
    {
        if ($this->room === null) {
            return;
        }

        $roomModel = $this->resolveRoom();
        $session = $roomModel->gameSession;

        $this->gameSessionId = $session->id;
        $this->hydrateFromState($session->state);

        $this->mySeatIndex = RoomSeat::where('room_id', $roomModel->id)
            ->where('user_id', Auth::id())
            ->value('seat_index');
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function hydrateFromState(array $state): void
    {
        $this->players = array_map(
            fn (array $seat) => [
                'name' => $seat['name'],
                'scores' => $seat['scores'],
                'yahtzeeBonusCount' => $seat['yahtzeeBonusCount'],
            ],
            $state['seats'],
        );

        $this->currentPlayer = $state['currentSeat'];
        $this->dice = $state['dice'];
        $this->kept = $state['kept'];
        $this->rollsLeft = $state['rollsLeft'];
        $this->gameOver = $state['gameOver'];
        $this->started = true;
    }

    private function resolveRoom(): Room
    {
        return Room::where('code', $this->room)->firstOrFail();
    }

    /**
     * Persists the current shared state onto the room's GameSession and
     * broadcasts it to every other seat/spectator -- called after every
     * successful mutating action while online (see roll()/toggleKeep()/
     * scoreCategory() below). Wrapped in a transaction with a row lock
     * more as cheap insurance against a single seat's own double-tap/retry
     * racing itself than as a full concurrency solution -- only the
     * current seat can ever mutate state (every action is gated on
     * `$this->mySeatIndex === $this->currentPlayer`), so cross-seat races
     * aren't possible here the way they would be for, say, Family Feud's
     * steal window.
     */
    private function syncOnlineState(): void
    {
        $state = [
            'seats' => array_map(
                fn (array $player, int $index) => [
                    'seatIndex' => $index,
                    'name' => $player['name'],
                    'scores' => $player['scores'],
                    'yahtzeeBonusCount' => $player['yahtzeeBonusCount'],
                ],
                $this->players,
                array_keys($this->players),
            ),
            'currentSeat' => $this->currentPlayer,
            'dice' => $this->dice,
            'kept' => $this->kept,
            'rollsLeft' => $this->rollsLeft,
            'gameOver' => $this->gameOver,
        ];

        DB::transaction(function () use ($state) {
            GameSession::where('id', $this->gameSessionId)->lockForUpdate()->first();
            GameSession::find($this->gameSessionId)->update(['state' => $state]);
        });

        RoomGameStateUpdated::dispatch($this->resolveRoom(), $state);
    }

    /**
     * The Echo callback for a custom broadcast event delivers the whole
     * broadcastWith() payload as one positional argument (unlike a local,
     * same-request Livewire dispatch, which binds by name) -- matches
     * game-lobby's own onReadyToggled(array $event) for the same reason.
     *
     * @param  array{state: array<string, mixed>}  $event
     */
    public function onStateUpdated(array $event): void
    {
        $this->hydrateFromState($event['state']);
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        if ($this->room === null) {
            return [];
        }

        return [
            "echo-presence:room.{$this->room},RoomGameStateUpdated" => 'onStateUpdated',
        ];
    }

    public function createRoom(): void
    {
        // Hosting while logged out now works the same way joining already
        // did -- see joinRoom() below for why a guest account (not true
        // anonymity) is required either way. See boggle/⚡lobby.blade.php's
        // own createRoom(), which this mirrors exactly.
        if (Auth::guest()) {
            Auth::login(User::createGuest($this->guestName));

            if (request()->hasSession()) {
                request()->session()->regenerate();
            }
        }

        $room = Room::create(['host_user_id' => Auth::id()]);

        $this->redirect(route('yahtzee.room', ['code' => $room->code]));
    }

    public function joinRoom(): void
    {
        $code = strtoupper(trim($this->joinID));
        $room = Room::notExpired()->where('code', $code)->first();

        if (! $room) {
            $this->joinError = 'No active room with that code.';

            return;
        }

        // A room's presence channel always authenticates via the guard (see
        // routes/channels.php) -- there's no "anonymous" option in Laravel's
        // broadcasting auth -- so joining without a real account still needs
        // a real `users` row. Only hosting (createRoom() above) is actually
        // restricted to non-guest accounts.
        if (Auth::guest()) {
            Auth::login(User::createGuest($this->guestName));

            // Guarded rather than unconditional -- Livewire::test() drives
            // this component directly, without the full HTTP kernel/session
            // middleware a real request goes through, so there's no session
            // store attached to request() there.
            if (request()->hasSession()) {
                request()->session()->regenerate();
            }
        }

        $this->redirect(route('yahtzee.room', ['code' => $room->code]));
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
        abort_unless($this->room === null || $this->mySeatIndex === $this->currentPlayer, 403);

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

        $this->syncOnlineStateIfNeeded();
    }

    public function toggleKeep(int $index): void
    {
        abort_unless($this->room === null || $this->mySeatIndex === $this->currentPlayer, 403);

        if ($this->gameOver || $this->dice === []) {
            return;
        }

        $this->kept[$index] = ! $this->kept[$index];

        $this->syncOnlineStateIfNeeded();
    }

    public function scoreCategory(string $categoryValue): void
    {
        abort_unless($this->room === null || $this->mySeatIndex === $this->currentPlayer, 403);

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

        // After advanceTurn(), not before -- this covers both "next turn"
        // and "that was the last category" (advanceTurn() calls endGame()
        // internally) with a single broadcast either way, so other seats
        // learn "game over" from the same state update rather than needing
        // a second, separate event.
        $this->syncOnlineStateIfNeeded();
    }

    private function syncOnlineStateIfNeeded(): void
    {
        if ($this->room !== null) {
            $this->syncOnlineState();
        }
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
        // Not supported online -- rewinding shared state that other seats
        // may have already seen broadcast is a real design problem (whose
        // view wins?) with no user request driving it, so it's cut here
        // rather than half-solved. The button is hidden in Blade for the
        // same reason; this guard is defense in depth against a stale
        // client still showing it.
        if ($this->room !== null || $this->gameOver || $this->history === []) {
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

        if ($this->room !== null) {
            // The GameSession already exists (pages::yahtzee.room's
            // onRoomStarting() created it when the game started) -- just
            // mark it ended, rather than creating a second one.
            $session = GameSession::findOrFail($this->gameSessionId);
            $session->update(['ended_at' => now()]);

            // Every seat is a real (if possibly guest) account -- see
            // pages::yahtzee.room::onRoomStarting() -- so online games
            // never need $meIndex's guessing; every seat's own user_id is
            // known for certain, and every distinct logged-in account gets
            // its own correctly attributed GameParticipant row.
            $seatUserIds = RoomSeat::where('room_id', $this->resolveRoom()->id)
                ->orderBy('seat_index')
                ->pluck('user_id')
                ->all();
        } else {
            $session = GameSession::create([
                'type' => GameType::Yahtzee,
                'mode' => GameMode::Local,
                'settings' => ['player_count' => count($this->players)],
                'started_at' => now(),
                'ended_at' => now(),
            ]);
            $seatUserIds = null;
        }

        foreach ($this->players as $i => $player) {
            $total = $totals[$i];

            $result = match (true) {
                $total !== $topScore => GameResult::Loss,
                $winners > 1 => GameResult::Draw,
                default => GameResult::Win,
            };

            if ($seatUserIds !== null) {
                $userId = $seatUserIds[$i];
                $guestName = null;
            } else {
                // Pass-and-play has no way to know which local player is
                // the account holder in general (see docs/CHANGELOG.md) --
                // $meIndex is the host explicitly declaring it during
                // setup, the same way Boggle's own solo games attribute
                // user_id vs guest_name.
                $isMe = $i === $this->meIndex && Auth::check();
                $userId = $isMe ? Auth::id() : null;
                $guestName = $isMe ? null : $player['name'];
            }

            GameParticipant::create([
                'game_session_id' => $session->id,
                'user_id' => $userId,
                'guest_name' => $guestName,
                'score' => $total,
                'result' => $result,
                'stats' => ['scores' => $player['scores'], 'yahtzee_bonus_count' => $player['yahtzeeBonusCount']],
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
        // Hidden in Blade for online games too (see the room's own "Back
        // to room" link instead) -- resetting to local setup would orphan
        // the shared room. Guarded here as well in case of a stale client.
        if ($this->room !== null) {
            return;
        }

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

        // A watch-only device (see isSpectator's own docblock) never shows
        // a live possible score for an open category, even for the seat
        // whose turn it actually is -- isPickable() alone isn't enough to
        // catch this, since isMyTurn (which it checks) is deliberately
        // independent of $spectate.
        if ($this->isSpectator || ! $this->isPickable($playerIndex, $categoryValue)) {
            return null;
        }

        $category = YahtzeeCategory::from($categoryValue);

        return $this->engine->scoreCategory($category, $this->dice, $player['scores'])['score'];
    }

    public function isPickable(int $playerIndex, string $categoryValue): bool
    {
        return ! $this->gameOver
            && $playerIndex === $this->currentPlayer
            // Online: $playerIndex === $this->currentPlayer alone only
            // checks whose *column* this is, not who's actually looking at
            // it -- without isMyTurn too, any other seat viewing the
            // current player's column got a clickable cell wired up
            // (wire:click="scoreCategory(...)"), which then 403'd on the
            // server the moment they tapped it instead of just not being
            // clickable in the first place.
            && $this->isMyTurn
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

    /**
     * True for an online room connection that doesn't occupy a seat at
     * all, or for a seated player who's explicitly chosen to view this
     * device read-only anyway ($spectate -- see its own docblock). Never
     * true for local pass-and-play, which has no seat concept at all.
     * Drives the read-only view (see the Blade below): the dice values
     * and kept highlighting still show, but there's no clicking a die to
     * keep it, no Roll button, and no clicking a scorecard cell to score.
     */
    public function getIsSpectatorProperty(): bool
    {
        return $this->room !== null && ($this->mySeatIndex === null || $this->spectate);
    }

    /**
     * Always true for local pass-and-play (no seat to check); for an
     * online room, true only for the seat whose turn it actually is --
     * independent of $spectate, which only ever affects what this
     * particular device *shows*, never who's actually allowed to act.
     */
    public function getIsMyTurnProperty(): bool
    {
        return $this->room === null || $this->mySeatIndex === $this->currentPlayer;
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

<x-slot:title>Yacht — Low Stim Play</x-slot>
<x-slot:description>A full Yacht scorecard for 1-8 players, including the upper-section bonus and Joker rules, in a calm, low-contrast design.</x-slot>
<x-slot:bodyClass>yahtzee-play</x-slot:bodyClass>
<x-slot:fullscreenToggle>1</x-slot:fullscreenToggle>
<x-slot:rulesTitle>Yacht Rules</x-slot:rulesTitle>
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
        <li>Yacht: scores 50 for five of a kind.</li>
        <li>Chance: always scores the sum of all five dice, whatever you rolled.</li>
        <li>Yacht Bonus: once your Yacht box shows 50, every additional five-of-a-kind you roll earns a 100-point bonus chip, on top of whatever category you score that turn.</li>
        <li>Joker rule: once your Yacht box has been filled at all (scored 50 or forced to 0), a later five-of-a-kind lets Full House / Small Straight / Large Straight score their fixed value (25 / 30 / 40) automatically &mdash; but only if the Upper Section box matching the number you rolled is already filled. If it's still open, those three categories score 0 for that roll instead, so scoring the matching Upper Section box (or Chance) is usually the better move.</li>
        <li>The game ends once every player has filled every category; whoever has the highest total wins (ties are possible).</li>
    </ul>
</x-slot:rules>
<div class="flex flex-col items-center w-full flex-1 min-h-0">
    {{--
        Teleported (see layouts/app.blade.php's #game-actions anchor) rather
        than a layout $barRight slot -- this button only appears once
        $started flips true, which happens after the page's first render,
        and a layout slot only ever captures its content once, at that
        first render.
    --}}
    @if ($started)
        @teleport('#game-actions')
            <div style="display: contents;">
                {{--
                    #scores-btn: hidden entirely on desktop-width screens
                    (see app.css) -- every player is already visible on
                    the main scorecard there, so the drawer is redundant.
                    It's the mobile-only single-seat scorecard (see
                    single-seat-mobile in app.css) that makes this button
                    relevant at all.
                --}}
                @if ($room !== null && ! $this->isSpectator)
                    <button type="button" id="scores-btn" onclick="document.getElementById('scoreboard-drawer').showModal()">Scores</button>
                @endif

                {{--
                    No id/CSS exception for this one (unlike #scores-btn
                    above) -- it's meant to just disappear like any other
                    button-bar action once fullscreen/chrome-hidden mode
                    is on, the default behavior for everything but the
                    fullscreen toggle itself and the Scores button.
                --}}
                @if ($room !== null && $mySeatIndex !== null)
                    <button type="button" wire:click="$set('spectate', {{ $this->isSpectator ? 'false' : 'true' }})">
                        {{ $this->isSpectator ? 'Player view' : 'Watch only' }}
                    </button>
                @endif

                @if ($room === null)
                    <button type="button" wire:click="newGame">New Game</button>
                @elseif ($gameOver)
                    <a href="{{ route('yahtzee.room', ['code' => $room]) }}"><button type="button">Back to room</button></a>
                @endif
            </div>
        @endteleport
    @endif

    @unless ($started)
        <div class="setup" id="setup">
            <div class="heading">Yacht</div>

            @unless (Auth::check() && ! Auth::user()->is_guest)
                <input type="text" wire:model="guestName" maxlength="24" placeholder="Your name (optional)">
            @endunless

            <button type="button" wire:click="createRoom">Host an Online Room</button>

            <div class="status-line">or</div>

            <form wire:submit="joinRoom" class="flex gap-2 w-full">
                <input type="text" id="join" wire:model="joinID" placeholder="Room ID" autocomplete="off" style="flex: 1; text-transform: uppercase;">
                <button type="submit">Join</button>
            </form>

            @if ($joinError)
                <p class="status-line" style="color: var(--warn);">{{ $joinError }}</p>
            @endif

            <div class="status-line">or play locally:</div>

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

            {{--
                Dice values and kept/selected highlighting are always
                visible, spectator or not -- a spectator still benefits
                from watching the live roll. Clicking a die to keep it and
                the Roll button itself are both only ever shown to the
                seat whose turn it actually is, and never at all to a
                spectator (see isSpectator's own docblock) -- hidden
                outright, not just disabled, since a disabled-but-visible
                Roll button invited clicking it anyway on the exact same
                class of bug isPickable() just got fixed for.
            --}}
            <div class="dice-area">
                <div class="row">
                    @for ($i = 0; $i < self::DICE_COUNT; $i++)
                        <div class="die {{ ($kept[$i] ?? false) ? 'kept' : '' }}" @if ($this->isMyTurn && ! $this->isSpectator) wire:click="toggleKeep({{ $i }})" @endif>
                            <span class="number">{{ $dice[$i] ?? '' }}</span>
                        </div>
                    @endfor
                </div>

                <div class="controls">
                    @if ($this->isMyTurn && ! $this->isSpectator)
                        <button type="button" id="rollBtn" wire:click="roll" @disabled($gameOver || $rollsLeft <= 0)>Roll</button>
                    @endif
                    {{-- Undo isn't supported online at all (see undo()'s own note) --}}
                    @if ($room === null)
                        <button type="button" id="undoBtn" wire:click="undo" @disabled($gameOver || $history === [])>Undo</button>
                    @endif
                </div>
            </div>

            {{--
                mySeatIndex, not null -- on mobile (see app.css), this
                narrows the table to just this seat's own column, since
                the "Scores" drawer below already exists specifically to
                show everyone. Desktop-width screens ignore this marker
                entirely and keep showing every column here regardless.
            --}}
            <x-yahtzee-scorecard-table
                :livewire="$this"
                :players="$players"
                :currentPlayer="$currentPlayer"
                :gameOver="$gameOver"
                :readOnly="$this->isSpectator"
                :mySeatIndex="$mySeatIndex"
            />
        </div>

        {{--
            The scoreboard drawer -- lets a seated player check everyone's
            scores on demand, since their own phone's main view (above)
            only ever shows their own column. A full-screen sheet rather
            than a small centered modal, since it's only ever opened on
            mobile in the first place (#scores-btn is desktop-hidden, see
            app.css) -- deliberately always shows every column (no
            mySeatIndex passed), unlike the main view above.

            wire:ignore.self -- <dialog>'s open/closed state lives only in
            the browser's native DOM (toggled imperatively by
            showModal()/close() below, never a tracked Livewire property),
            so it isn't part of what the server renders at all. Without
            this, another seat's action (roll/keep/score) broadcasts a
            state update, Livewire re-renders and morphs this component's
            whole tree to match, and morphing resets this element's own
            attributes back to that server-rendered baseline -- which
            never has `open` on it -- silently closing the dialog out
            from under whoever had it open. `.self`, not a bare
            wire:ignore, so the scorecard *inside* still updates live
            while it's open; only the dialog's own open/closed state is
            left alone.
        --}}
        @if ($room !== null && ! $this->isSpectator)
            <dialog wire:ignore.self class="scoreboard-dialog" id="scoreboard-drawer" aria-labelledby="scoreboard-drawer-title" tabindex="-1">
                <div class="rules-dialog-header">
                    <div class="rules-dialog-title" id="scoreboard-drawer-title">Scores</div>
                    <button type="button" class="rules-dialog-close" aria-label="Close" onclick="document.getElementById('scoreboard-drawer').close()">&times;</button>
                </div>

                {{--
                    Status text + the current dice roll, same live values as
                    the main view above -- a seated player checking the
                    drawer wants to know whose turn it is and what's been
                    rolled without closing it first. No wire:click on the
                    dice and no Roll/Undo controls here, since this is a
                    view-only copy (drawer-dice-area below shrinks it to
                    leave more room for the scorecard, per docs/TODO.md).
                --}}
                <div class="status">{{ $this->statusText }}</div>

                <div class="dice-area drawer-dice-area">
                    <div class="row">
                        @for ($i = 0; $i < self::DICE_COUNT; $i++)
                            <div class="die {{ ($kept[$i] ?? false) ? 'kept' : '' }}">
                                <span class="number">{{ $dice[$i] ?? '' }}</span>
                            </div>
                        @endfor
                    </div>
                </div>

                {{--
                    .card-wrap (inside the scorecard-table component) only
                    sizes its cqh-based row heights correctly when *it*
                    has a definite height to measure against -- on the
                    live game view that comes from the surrounding
                    #yahtzee-game flex column (itself bounded by
                    100dvh), which doesn't exist inside a <dialog>. This
                    wrapper (flex-1/min-h-0, matching #yahtzee-game's own
                    structure) fills whatever's left of the full-screen
                    dialog after the header instead, so the table renders
                    at a normal, legible size rather than collapsing to
                    ~0.
                --}}
                <div class="flex flex-col flex-1 min-h-0">
                    <x-yahtzee-scorecard-table
                        :livewire="$this"
                        :players="$players"
                        :currentPlayer="$currentPlayer"
                        :gameOver="$gameOver"
                        :readOnly="true"
                    />
                </div>
            </dialog>
        @endif
    @endunless
</div>

@script
<script>
    // body's own reactively-toggled class (rather than the server-rendered
    // $bodyClass slot, since a Livewire round trip only re-renders this
    // component's own root element -- <body>, sitting outside it in the
    // surrounding layout, never gets touched by that diff; $started flips
    // true via the startGame() AJAX call, well after the layout's one and
    // only render of that slot). Matches the same fix already applied to
    // Boggle's play page.
    document.body.classList.toggle('in-game', $wire.started);
    $wire.$watch('started', (started) => {
        document.body.classList.toggle('in-game', started);
    });

    // Online rooms can show up to 3 right-side buttons at once (Scores,
    // Watch only/Player view, New Game/Back to room) on top of Home/Rules
    // on the left -- .many-buttons' existing compact padding/gap (see
    // app.css, already used by welcome.blade.php for its own 3-button
    // case) is what keeps that from crowding the fullscreen toggle off a
    // narrow phone screen. $wire.room never changes after mount, so
    // (unlike $wire.started above) this only needs to run once, not watch
    // for changes -- but still has to happen here, in JS, rather than via
    // a server-rendered class, for the same "layout is outside this
    // component's own re-rendered root" reason as document.body itself.
    if ($wire.room !== null) {
        document.querySelector('.button-bar')?.classList.add('many-buttons');
    }
</script>
@endscript

<script>
    // Same fix as boggle/⚡lobby.blade.php's own join field: nothing
    // here sets autofocus, but mobile Safari can still focus this field
    // and raise the keyboard on its own (back-forward cache restore, or
    // its verification-code autofill heuristics treating a short,
    // "code"-labeled field as an OTP input) -- blur it whenever it's
    // focused before the user has actually interacted with the page.
    let userInteracted = false;
    window.addEventListener('pointerdown', () => { userInteracted = true; }, { once: true, capture: true });

    const blurAutofocusedJoinID = () => {
        const joinID = document.getElementById('join');
        if (! userInteracted && joinID && document.activeElement === joinID) {
            joinID.blur();
        }
    };

    window.addEventListener('pageshow', blurAutofocusedJoinID);
    window.addEventListener('load', () => setTimeout(blurAutofocusedJoinID, 0));
</script>
