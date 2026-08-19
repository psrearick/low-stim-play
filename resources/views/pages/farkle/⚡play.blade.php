<?php

use App\Enums\GameMode;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Events\RoomGameStateUpdated;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Services\Farkle\DiceRoller;
use App\Services\Farkle\GameEngine;
use App\Services\Farkle\Scorer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    public const MIN_PLAYERS = 1;

    public const MAX_PLAYERS = 8;

    public const DICE_COUNT = 6;

    /**
     * @var list<int>
     */
    public const TARGET_SCORE_OPTIONS = [5000, 7500, 10000, 15000];

    /**
     * Shown instead of TARGET_SCORE_OPTIONS whenever Spicy is on -- see
     * docs/specs/farkle.md §6.6: "Default target score for a Spicy game
     * is 25,000, not the normal mode's 10,000," still a host-configurable
     * button choice like the normal set, just scaled up to match.
     *
     * @var list<int>
     */
    public const SPICY_TARGET_SCORE_OPTIONS = [15000, 25000, 35000, 50000];

    public const NORMAL_DEFAULT_TARGET_SCORE = 10000;

    public const SPICY_DEFAULT_TARGET_SCORE = 25000;

    public int $playerCount = 2;

    /**
     * @var list<string>
     */
    public array $playerNames = ['', ''];

    /**
     * Local-play-only setup: an online room's host configures these on
     * pages::farkle.room instead (see its own onRoomStarting()), since a
     * room's game settings need to be visible/agreed on before anyone
     * plays, not just to whoever happens to load this page first.
     */
    public int $targetScore = self::NORMAL_DEFAULT_TARGET_SCORE;

    public bool $withFinalRound = true;

    /**
     * The Spicy variant toggle (docs/specs/farkle.md §6) -- doubles
     * scoring combinations that include a spicy die and enables the
     * instant-win six-spicy roll. Independent of $highStakesEnabled.
     */
    public bool $spicyEnabled = false;

    /**
     * The High Stakes toggle (docs/specs/farkle.md §6.5) -- independent
     * of $spicyEnabled, can be on or off regardless of it. Lets the
     * player opening a turn take over the previous player's leftover
     * dice and banked turn amount instead of a fresh six, when the
     * previous turn ended by banking (see $lastBankInheritance).
     */
    public bool $highStakesEnabled = false;

    public bool $started = false;

    public bool $gameOver = false;

    /**
     * @var list<array{name: string, bankedScore: int, hasQualified: bool, farkleCount: int, hotDiceCount: int, bestTurnScore: int}>
     */
    public array $players = [];

    public int $currentPlayer = 0;

    /**
     * The dice currently awaiting a selection -- empty means either "not
     * rolled yet this turn" or "between turns," since (unlike Yahtzee's
     * kept-in-place dice) a Farkle selection is applied and cleared
     * immediately by whichever action resolves it (bank() or
     * rollAgain()), never left sitting between requests.
     *
     * @var list<int>
     */
    public array $dice = [];

    /**
     * @var list<bool>
     */
    public array $selected = [];

    /**
     * Which of the currently-rolled $dice are spicy this roll -- parallel
     * to $dice/$selected, meaningless (always false) unless
     * $spicyEnabled. See docs/specs/farkle.md §6.1.
     *
     * @var list<bool>
     */
    public array $dieSpicy = [];

    /**
     * The still-un-held die "labels" (1-6, docs/specs/farkle.md §6.1) for
     * the current turn -- always maintained, even when Spicy/High Stakes
     * are both off, since it's what every roll actually asks
     * DiceRoller::rollSlots() for. Its *length* always equals
     * `DICE_COUNT - $heldDiceCount` (kept in lockstep everywhere
     * $heldDiceCount changes); its labels only matter when $spicyEnabled
     * (they decide which rolled values come back spicy) or
     * $highStakesEnabled (a bank at the end of a turn hands this list's
     * current length to the next player as how many dice they can take
     * over -- see $lastBankInheritance).
     *
     * @var list<int>
     */
    public array $activeDieSlots = [];

    /**
     * Set by bankTurnScore() whenever $highStakesEnabled and a turn ends
     * by banking (never by busting -- see docs/specs/farkle.md §6.5);
     * null otherwise. Offered as a choice to the very next player's own
     * turn-opening roll only, then cleared by roll()/rollTakeOver()
     * regardless of which they pick -- it never survives past that one
     * decision.
     *
     * @var null|array{turnScore: int, remainingSlots: list<int>}
     */
    public ?array $lastBankInheritance = null;

    /**
     * Every die value locked in so far *this turn*, across every roll --
     * unlike $dice/$selected (which only ever reflect the current roll),
     * this never gets cleared mid-turn, so a multi-roll turn still shows
     * everything already set aside instead of it silently disappearing.
     * See docs/concerns.md: "Bank[ed] dice should show as banked, not
     * disappear."
     *
     * @var list<int>
     */
    public array $bankedDiceThisTurn = [];

    /**
     * This turn's running total, banked all at once by bank() or lost
     * entirely on a bust -- not the same as any single roll's score.
     */
    public int $turnScore = 0;

    /**
     * How many of the 6 dice are already set aside this turn. Resets to 0
     * (not decremented back down) the moment it reaches 6 -- "hot dice" --
     * since that means every die has scored and the player earns a fresh
     * set of 6 to keep going, on top of whatever's already in turnScore.
     */
    public int $heldDiceCount = 0;

    public ?string $message = null;

    /**
     * What kind of thing $message is describing -- 'bust'|'banked'|
     * 'gameOver'|null -- purely for Blade to style it distinctly (see
     * docs/concerns.md: a Farkle should be "more clear... in bigger
     * letters"), never used for game logic.
     */
    public ?string $messageType = null;

    /**
     * Which local player, if any, is the authenticated account holder --
     * mirrors pages::yahtzee.play's own $meIndex exactly.
     */
    public ?int $meIndex = null;

    /**
     * The online room this play-through belongs to, if any -- mirrors
     * pages::yahtzee.play's own $room.
     */
    #[Url]
    public ?string $room = null;

    public ?int $gameSessionId = null;

    public ?int $mySeatIndex = null;

    /**
     * See pages::yahtzee.play's own $spectate docblock -- identical
     * per-device "watch only" affordance.
     */
    #[Url]
    public bool $spectate = false;

    /**
     * Set the instant any player's bankedScore first reaches targetScore,
     * when withFinalRound is on -- from then on every other player gets
     * exactly one more turn (finalRoundRemaining counts those down) before
     * the game ends, regardless of whether they also cross the target.
     */
    public bool $finalRoundActive = false;

    public ?int $finalRoundRemaining = null;

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

        // A stale tab/bookmark, or a hand-edited ?room= for a room that's
        // actively playing something else, can land here with no session
        // at all or one belonging to a different game -- hydrateFromState()
        // would otherwise throw on a null/foreign state shape. Treat it the
        // same as "no active game here" and bounce to the room hub instead.
        if ($session === null || $session->type !== GameType::Farkle) {
            $this->redirect(route('room.show', ['code' => $roomModel->code]));

            return;
        }

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
                'bankedScore' => $seat['bankedScore'],
                'hasQualified' => $seat['hasQualified'],
                'farkleCount' => $seat['farkleCount'],
                'hotDiceCount' => $seat['hotDiceCount'],
                'bestTurnScore' => $seat['bestTurnScore'],
            ],
            $state['seats'],
        );

        $this->currentPlayer = $state['currentSeat'];
        $this->dice = $state['dice'];
        $this->selected = $state['selected'];
        // ?? on these: a room's GameSession.state predating the Spicy
        // variant (docs/specs/farkle.md §6) won't have them yet, and an
        // in-progress old game reconnecting shouldn't hard-error.
        // activeDieSlots' own fallback derives a same-length (if not
        // necessarily same-label) stand-in from heldDiceCount, since
        // every roll depends on its *length* regardless of Spicy.
        $this->dieSpicy = $state['dieSpicy'] ?? [];
        $this->activeDieSlots = $state['activeDieSlots']
            ?? range(1, self::DICE_COUNT - $state['heldDiceCount']);
        $this->lastBankInheritance = $state['lastBankInheritance'] ?? null;
        $this->bankedDiceThisTurn = $state['bankedDiceThisTurn'];
        $this->turnScore = $state['turnScore'];
        $this->heldDiceCount = $state['heldDiceCount'];
        $this->targetScore = $state['targetScore'];
        $this->withFinalRound = $state['withFinalRound'];
        $this->spicyEnabled = $state['spicyEnabled'] ?? false;
        $this->highStakesEnabled = $state['highStakesEnabled'] ?? false;
        $this->finalRoundActive = $state['finalRoundActive'];
        $this->finalRoundRemaining = $state['finalRoundRemaining'];
        $this->gameOver = $state['gameOver'];
        $this->message = $state['message'];
        $this->messageType = $state['messageType'];
        $this->started = true;
    }

    private function resolveRoom(): Room
    {
        return Room::where('code', $this->room)->firstOrFail();
    }

    /**
     * Mirrors pages::yahtzee.play's own syncOnlineState() exactly --
     * persist the whole shared state, then broadcast it.
     */
    private function syncOnlineState(): void
    {
        $state = [
            'seats' => array_map(
                fn (array $player, int $index) => ['seatIndex' => $index, ...$player],
                $this->players,
                array_keys($this->players),
            ),
            'currentSeat' => $this->currentPlayer,
            'dice' => $this->dice,
            'selected' => $this->selected,
            'dieSpicy' => $this->dieSpicy,
            'activeDieSlots' => $this->activeDieSlots,
            'lastBankInheritance' => $this->lastBankInheritance,
            'bankedDiceThisTurn' => $this->bankedDiceThisTurn,
            'turnScore' => $this->turnScore,
            'heldDiceCount' => $this->heldDiceCount,
            'targetScore' => $this->targetScore,
            'withFinalRound' => $this->withFinalRound,
            'spicyEnabled' => $this->spicyEnabled,
            'highStakesEnabled' => $this->highStakesEnabled,
            'finalRoundActive' => $this->finalRoundActive,
            'finalRoundRemaining' => $this->finalRoundRemaining,
            'gameOver' => $this->gameOver,
            'message' => $this->message,
            'messageType' => $this->messageType,
        ];

        DB::transaction(function () use ($state) {
            GameSession::where('id', $this->gameSessionId)->lockForUpdate()->first();
            GameSession::find($this->gameSessionId)->update(['state' => $state]);
        });

        RoomGameStateUpdated::dispatch($this->resolveRoom(), $state);
    }

    /**
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

    /**
     * @return \Illuminate\Support\Collection<int, Room>
     */
    public function getHostedRoomsProperty(): \Illuminate\Support\Collection
    {
        if (Auth::guest()) {
            return collect();
        }

        return Room::where('host_user_id', Auth::id())->notExpired()->get();
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

    /**
     * Resets $targetScore to whichever mode's own default (10,000 /
     * 25,000) rather than leaving a previously-picked value in place --
     * see docs/specs/farkle.md §6.6, and setPlayerCount() above for the
     * same "toggling a setting resets dependent state" pattern.
     */
    public function setSpicyEnabled(bool $enabled): void
    {
        $this->spicyEnabled = $enabled;
        $this->targetScore = $enabled ? self::SPICY_DEFAULT_TARGET_SCORE : self::NORMAL_DEFAULT_TARGET_SCORE;
    }

    public function startGame(): void
    {
        $this->players = array_map(
            fn (int $i) => [
                'name' => trim($this->playerNames[$i] ?? '') !== '' ? trim($this->playerNames[$i]) : 'Player '.($i + 1),
                'bankedScore' => 0,
                'hasQualified' => false,
                'farkleCount' => 0,
                'hotDiceCount' => 0,
                'bestTurnScore' => 0,
            ],
            range(0, $this->playerCount - 1),
        );

        $this->currentPlayer = 0;
        $this->gameOver = false;
        $this->started = true;
        $this->message = null;
        $this->messageType = null;
        $this->startTurn();
    }

    public function roll(DiceRoller $diceRoller): void
    {
        abort_unless($this->room === null || $this->mySeatIndex === $this->currentPlayer, 403);

        if ($this->gameOver || $this->dice !== []) {
            return;
        }

        // Declining an offered High Stakes take-over still forfeits it --
        // see $lastBankInheritance's own docblock. Harmless no-op when
        // nothing was on offer.
        $this->lastBankInheritance = null;

        $this->performRoll($diceRoller);
        $this->syncOnlineStateIfNeeded();
    }

    /**
     * The High Stakes alternative to roll() (docs/specs/farkle.md §6.5):
     * instead of a fresh six, opens the turn with the previous player's
     * entire banked turn score as a starting head start and rerolls only
     * however many dice they'd left un-held -- fresh random values, not
     * their literal prior pips (the *count* carries over, not the dice
     * themselves). That head start is now fully this player's own turn
     * score, at risk the same as anything else they roll this turn.
     */
    public function rollTakeOver(DiceRoller $diceRoller): void
    {
        abort_unless($this->room === null || $this->mySeatIndex === $this->currentPlayer, 403);

        if ($this->gameOver || ! $this->canTakeOverValue()) {
            return;
        }

        $this->turnScore = $this->lastBankInheritance['turnScore'];
        $this->activeDieSlots = $this->lastBankInheritance['remainingSlots'];
        $this->heldDiceCount = self::DICE_COUNT - count($this->activeDieSlots);
        $this->lastBankInheritance = null;

        $this->performRoll($diceRoller);
        $this->syncOnlineStateIfNeeded();
    }

    public function toggleSelect(int $index): void
    {
        abort_unless($this->room === null || $this->mySeatIndex === $this->currentPlayer, 403);

        if ($this->gameOver || $this->dice === []) {
            return;
        }

        $this->selected[$index] = ! ($this->selected[$index] ?? false);

        $this->syncOnlineStateIfNeeded();
    }

    /**
     * Applies the current selection and immediately rolls the remaining
     * dice -- a single click for "lock these in and keep going," matching
     * docs/specs/farkle.md's turn-UI shape. Busts (no scoring dice in the
     * new roll) end the turn right here, same as the very first roll of a
     * turn would.
     */
    public function rollAgain(DiceRoller $diceRoller): void
    {
        abort_unless($this->room === null || $this->mySeatIndex === $this->currentPlayer, 403);

        if ($this->gameOver || ! $this->canRollAgainValue()) {
            return;
        }

        $this->applySelection();
        $this->performRoll($diceRoller);
        $this->syncOnlineStateIfNeeded();
    }

    /**
     * Banks every scoring die in the current roll, not just whichever
     * ones happen to be manually clicked -- see docs/concerns.md: a
     * player who clicks Bank without having clicked every scoring die
     * first should still get credit for all of them, not just the ones
     * they selected. $this->bankableAmount (used for the button's own
     * label) is computed via the exact same engine call, so what's shown
     * is always exactly what gets banked.
     */
    public function bank(): void
    {
        abort_unless($this->room === null || $this->mySeatIndex === $this->currentPlayer, 403);

        if ($this->gameOver || ! $this->canBankValue()) {
            return;
        }

        // Not appended to $bankedDiceThisTurn -- bank() ends the turn
        // immediately, and that list resets for the next turn right
        // after anyway (see startTurn(), called from advanceTurn()),
        // so there's no render where showing these dice as "banked"
        // separately from the scoreboard update would ever matter.
        $this->turnScore += $this->engine->scoreMaximal($this->dice, $this->activeDieSpicy());

        // bank() never asks the player to select anything (every scoring
        // die in $dice is auto-included regardless), so this is the only
        // place that finds out which of this final roll's dice *didn't*
        // score -- exactly the ones High Stakes (docs/specs/farkle.md
        // §6.5) can hand off to the next player as still "non-frozen".
        $nonScoringSlots = array_map(
            fn (int $i) => $this->activeDieSlots[$i],
            $this->engine->nonScoringIndices($this->dice),
        );
        $this->activeDieSlots = $nonScoringSlots;

        $this->dice = [];
        $this->selected = [];
        $this->dieSpicy = [];
        $this->bankTurnScore();
        $this->syncOnlineStateIfNeeded();
    }

    /**
     * Ends the game right now, before the target score is reached --
     * gated to the room's host online, or any player in local pass-and-play
     * (there's no "host" concept there), mirroring Family Feud's own
     * manual endGame() gating exactly. See docs/specs/farkle.md's "Win
     * condition" section.
     */
    public function endGameManually(): void
    {
        abort_unless($this->room === null || Auth::id() === $this->resolveRoom()->host_user_id, 403);

        if ($this->gameOver) {
            return;
        }

        $this->finishGame();
        $this->syncOnlineStateIfNeeded();
    }

    /**
     * Always rolls via DiceRoller::rollSlots() against $activeDieSlots
     * (docs/specs/farkle.md §6.1), even in a plain non-Spicy game --
     * $activeDieSlots' *length* always equals however many dice are left
     * to roll (kept in lockstep with $heldDiceCount everywhere both
     * change), so this is just as correct a source of "how many dice"
     * as the old `DICE_COUNT - $heldDiceCount` arithmetic was, and it
     * means every roll (Spicy or not) goes through one code path. The
     * resulting spicy flags are simply never looked at when
     * !$spicyEnabled -- $dieSpicy stays all-false, doubling nothing.
     */
    private function performRoll(DiceRoller $diceRoller): void
    {
        $rolled = $diceRoller->rollSlots($this->activeDieSlots);
        $values = $rolled['values'];
        $spicy = $this->spicyEnabled ? $rolled['spicy'] : array_fill(0, count($values), false);

        if ($this->spicyEnabled && $this->engine->isInstantWin($spicy)) {
            $this->dice = $values;
            $this->dieSpicy = $spicy;
            $this->selected = array_fill(0, count($values), false);
            $this->winInstantly();

            return;
        }

        if (! $this->engine->hasAnyScoringDice($values)) {
            $name = $this->players[$this->currentPlayer]['name'];
            $this->players[$this->currentPlayer]['farkleCount']++;
            $this->turnScore = 0;
            $this->heldDiceCount = 0;
            $this->activeDieSlots = range(1, self::DICE_COUNT);
            $this->dice = [];
            $this->selected = [];
            $this->dieSpicy = [];
            $this->message = "{$name} busted — the turn's points are lost.";
            $this->messageType = 'bust';
            $this->advanceTurn();

            return;
        }

        $this->dice = $values;
        $this->dieSpicy = $spicy;
        $this->selected = array_fill(0, count($values), false);
        $this->message = null;
        $this->messageType = null;
    }

    /**
     * Scores whatever's currently selected and folds it into this turn's
     * running total -- shared by both bank() and rollAgain(), each of
     * which calls this before doing its own thing (ending the turn vs.
     * immediately rolling the rest). Assumes canBank/canRollAgain already
     * confirmed the selection is valid and non-empty.
     */
    private function applySelection(): void
    {
        $dieSpicy = $this->activeDieSpicy();
        $values = [];
        $spicy = [];
        $heldSlots = [];

        foreach ($this->dice as $i => $value) {
            if ($this->selected[$i] ?? false) {
                $values[] = $value;
                $spicy[] = $dieSpicy[$i] ?? false;
                $heldSlots[] = $this->activeDieSlots[$i];
            }
        }

        $result = $this->engine->scoreSelection($values, $spicy);

        $this->turnScore += $result['score'];
        $this->heldDiceCount += count($values);
        $this->bankedDiceThisTurn = [...$this->bankedDiceThisTurn, ...$values];
        $this->activeDieSlots = array_values(array_diff($this->activeDieSlots, $heldSlots));

        if ($this->heldDiceCount === self::DICE_COUNT) {
            $this->heldDiceCount = 0;
            $this->activeDieSlots = range(1, self::DICE_COUNT);
            $this->players[$this->currentPlayer]['hotDiceCount']++;
        }

        $this->dice = [];
        $this->selected = [];
        $this->dieSpicy = [];
    }

    /**
     * The Spicy variant's instant-win condition (docs/specs/farkle.md
     * §6.4) -- ends the whole game right now, crediting the rolling
     * player a win regardless of their current banked score, and
     * *without* triggering a final round for everyone else the way
     * normally crossing $targetScore does.
     */
    private function winInstantly(): void
    {
        $this->message = null;
        $this->messageType = null;
        $this->finishGame($this->currentPlayer);
    }

    /**
     * $dieSpicy itself, but forced all-false whenever $spicyEnabled is
     * off -- real gameplay never populates $dieSpicy with a true flag
     * unless $spicyEnabled (see performRoll()), but this guards every
     * call site that feeds it to the engine against stale/hand-set state
     * disagreeing with the toggle (e.g. a room reconnecting mid-flight,
     * or a test forcing $dice/$dieSpicy directly) ever silently doubling
     * a non-Spicy game's score.
     *
     * @return list<bool>
     */
    private function activeDieSpicy(): array
    {
        return $this->spicyEnabled ? $this->dieSpicy : array_fill(0, count($this->dice), false);
    }

    private function bankTurnScore(): void
    {
        $player = $this->players[$this->currentPlayer];
        $player['bankedScore'] += $this->turnScore;
        $player['hasQualified'] = $player['hasQualified'] || $this->turnScore >= Scorer::MINIMUM_ENTRY_SCORE;
        $player['bestTurnScore'] = max($player['bestTurnScore'], $this->turnScore);
        $this->players[$this->currentPlayer] = $player;

        // Offered to the very next player's turn only -- see
        // $lastBankInheritance's own docblock and
        // docs/specs/farkle.md §6.5. $activeDieSlots defaults to a full
        // six when empty (a hot-dice-fully-used bank), per the rule's own
        // "even if...all six dice already used" carve-out.
        if ($this->highStakesEnabled) {
            $this->lastBankInheritance = [
                'turnScore' => $this->turnScore,
                'remainingSlots' => $this->activeDieSlots !== [] ? $this->activeDieSlots : range(1, self::DICE_COUNT),
            ];
        }

        $this->message = "{$player['name']} banked {$this->turnScore}.";
        $this->messageType = 'banked';

        // Only a *fresh* crossing starts the final round -- if it's already
        // active (someone else triggered it), this bank is just another of
        // the counted-down final turns, handled by advanceTurn() below.
        // $justTriggeredFinalRound matters there: without it, this same
        // bank's own fall-through call to advanceTurn() immediately
        // decremented finalRoundRemaining a second time, right after
        // setting it here -- the triggering player's turn isn't itself one
        // of the "everyone else's one more turn" turns being counted down.
        $justTriggeredFinalRound = false;

        if (! $this->finalRoundActive && $player['bankedScore'] >= $this->targetScore) {
            if ($this->withFinalRound) {
                $this->finalRoundActive = true;
                $this->finalRoundRemaining = count($this->players) - 1;
                $justTriggeredFinalRound = true;
            } else {
                $this->turnScore = 0;
                $this->finishGame();

                return;
            }
        }

        $this->turnScore = 0;
        $this->advanceTurn($justTriggeredFinalRound);
    }

    private function advanceTurn(bool $skipFinalRoundDecrement = false): void
    {
        if ($this->finalRoundActive && ! $skipFinalRoundDecrement) {
            $this->finalRoundRemaining--;

            if ($this->finalRoundRemaining <= 0) {
                $this->finishGame();

                return;
            }
        }

        $this->currentPlayer = ($this->currentPlayer + 1) % count($this->players);

        // Authoritative reset for the new current player's turn -- both
        // callers (a bust in performRoll(), a completed bank() in
        // bankTurnScore()) also reset dice/selected/turnScore by hand
        // right before calling this, but heldDiceCount specifically only
        // gets reset here: bank() ends the turn with heldDiceCount
        // possibly still nonzero (e.g. banked after setting aside 2 of 6
        // dice), and without this, the *next* player's turn would
        // silently start already short however many dice the previous
        // player had set aside.
        $this->startTurn();
    }

    private function startTurn(): void
    {
        $this->dice = [];
        $this->selected = [];
        $this->dieSpicy = [];
        $this->bankedDiceThisTurn = [];
        $this->turnScore = 0;
        $this->heldDiceCount = 0;
        $this->activeDieSlots = range(1, self::DICE_COUNT);
    }

    private function syncOnlineStateIfNeeded(): void
    {
        if ($this->room !== null) {
            $this->syncOnlineState();
        }
    }

    /**
     * Ends the game. `$forcedWinnerIndex` is only set by winInstantly()
     * (docs/specs/farkle.md §6.4) -- when present, that player wins
     * outright regardless of any banked score, and everyone else is a
     * plain loss (no draws possible), bypassing the normal
     * highest-bankedScore comparison entirely.
     */
    private function finishGame(?int $forcedWinnerIndex = null): void
    {
        $this->gameOver = true;
        $this->dice = [];
        $this->selected = [];
        $this->dieSpicy = [];
        $this->bankedDiceThisTurn = [];
        $this->turnScore = 0;
        $this->heldDiceCount = 0;
        $this->activeDieSlots = [];
        $this->lastBankInheritance = null;

        if ($forcedWinnerIndex !== null) {
            $topScore = $this->players[$forcedWinnerIndex]['bankedScore'];
            $winners = 1;
        } else {
            $topScore = collect($this->players)->max('bankedScore');
            $winners = collect($this->players)->where('bankedScore', $topScore)->count();
        }

        if ($this->room !== null) {
            $session = GameSession::findOrFail($this->gameSessionId);
            $session->update(['ended_at' => now()]);

            // Room.status/game_session_id otherwise stay exactly as
            // startGame() left them ('active', pointing at this now-
            // finished session) -- pages::farkle.room's own
            // redirectIfAlreadyStarted() only checks those two fields to
            // decide "bounce a visitor straight back into the game," with
            // no way to tell "still being played" from "already over."
            // Without this, the "Back to room" button above visibly did
            // nothing: it navigated to the room page, which immediately
            // redirected right back here. See docs/concerns.md.
            $this->resolveRoom()->update(['status' => RoomStatus::Finished]);

            $seatUserIds = RoomSeat::where('room_id', $this->resolveRoom()->id)
                ->orderBy('seat_index')
                ->pluck('user_id')
                ->all();
        } else {
            $session = GameSession::create([
                'type' => GameType::Farkle,
                'mode' => GameMode::Local,
                'settings' => [
                    'player_count' => count($this->players),
                    'target_score' => $this->targetScore,
                    'with_final_round' => $this->withFinalRound,
                    'spicy_enabled' => $this->spicyEnabled,
                    'high_stakes_enabled' => $this->highStakesEnabled,
                ],
                'started_at' => now(),
                'ended_at' => now(),
            ]);
            $seatUserIds = null;
        }

        foreach ($this->players as $i => $player) {
            // With a forced winner (an instant win), bankedScore doesn't
            // decide anything -- another player's total could coincidentally
            // equal $topScore without being the winner, so this checks the
            // seat index directly rather than falling through to the normal
            // score-comparison branches below.
            $result = match (true) {
                $forcedWinnerIndex !== null => $i === $forcedWinnerIndex ? GameResult::Win : GameResult::Loss,
                $player['bankedScore'] !== $topScore => GameResult::Loss,
                $winners > 1 => GameResult::Draw,
                default => GameResult::Win,
            };

            if ($seatUserIds !== null) {
                $userId = $seatUserIds[$i];
                $guestName = null;
            } else {
                $isMe = $i === $this->meIndex && Auth::check();
                $userId = $isMe ? Auth::id() : null;
                $guestName = $isMe ? null : $player['name'];
            }

            GameParticipant::create([
                'game_session_id' => $session->id,
                'user_id' => $userId,
                'guest_name' => $guestName,
                'score' => $player['bankedScore'],
                'result' => $result,
                'stats' => [
                    'farkle_count' => $player['farkleCount'],
                    'hot_dice_count' => $player['hotDiceCount'],
                    'best_turn_score' => $player['bestTurnScore'],
                ],
            ]);
        }

        if ($forcedWinnerIndex !== null) {
            $winnerName = $this->players[$forcedWinnerIndex]['name'];
            $this->message = "Game Over — {$winnerName} rolled an instant win (all six dice spicy)!";
        } else {
            $winnerName = collect($this->players)
                ->filter(fn ($player) => $player['bankedScore'] === $topScore)
                ->pluck('name')
                ->first();

            $this->message = $winners > 1
                ? "Game Over — It's a tie at {$topScore}!"
                : "Game Over — {$winnerName} wins with {$topScore}!";
        }

        $this->messageType = 'gameOver';
    }

    public function newGame(): void
    {
        if ($this->room !== null) {
            return;
        }

        $this->started = false;
        $this->gameOver = false;
        $this->players = [];
        $this->message = null;
        $this->messageType = null;
        $this->meIndex = null;
        $this->finalRoundActive = false;
        $this->finalRoundRemaining = null;
        $this->lastBankInheritance = null;
    }

    /**
     * Every "canX"/derived-value property below has a private, uncached
     * `xValue()` twin that the *actions* (roll()/rollAgain()/bank()/etc.)
     * call internally, while the public `getXProperty()` -- which Blade
     * reads -- just delegates to it. This split exists because of a real
     * bug found live: Livewire's legacy `getXxxProperty()` convention
     * (`SupportLegacyComputedPropertySyntax`) memoizes each property's
     * value the *first* time it's accessed within a request and reuses
     * that cached value for every later access in the same request. An
     * action method that read `$this->canRollAgain` in its own guard
     * *before* mutating $dice/$selected/$currentPlayer -- exactly what
     * rollAgain()/bank() used to do -- cached the *pre*-mutation answer,
     * and Blade's later render of `$this->canRollAgain` (after the
     * mutation) silently reused that same stale cached value instead of
     * recomputing. Confirmed via the raw server-rendered HTML itself
     * (not just the browser DOM): `rollAgainBtn` rendered with no
     * `disabled` attribute at all right after a rollAgain() call, even
     * though a fresh evaluation of the exact same condition (bypassing
     * the cache) correctly returned false. As long as every action uses
     * the private, uncached version, the public cached getter's first
     * (and only) access per request is Blade's own render -- always
     * correct, and still only computed once per render either way.
     */
    private function selectionScoreValue(): array
    {
        $dieSpicy = $this->activeDieSpicy();
        $values = [];
        $spicy = [];

        foreach ($this->dice as $i => $value) {
            if ($this->selected[$i] ?? false) {
                $values[] = $value;
                $spicy[] = $dieSpicy[$i] ?? false;
            }
        }

        return $this->engine->scoreSelection($values, $spicy);
    }

    /**
     * The score the current selection is worth, computed live from
     * $dice/$selected -- drives the on-screen "worth X points" hint.
     *
     * @return array{score: int, valid: bool}
     */
    public function getSelectionScoreProperty(): array
    {
        return $this->selectionScoreValue();
    }

    /**
     * $bankedDiceThisTurn chunked into one row per DICE_COUNT dice, so a
     * turn that goes past hot dice (more than 6 banked) renders as
     * multiple stacked rows -- one per roll -- instead of a single row
     * that keeps growing sideways (docs/concerns.md).
     *
     * @return list<list<int>>
     */
    public function getBankedDiceRowsProperty(): array
    {
        return array_chunk($this->bankedDiceThisTurn, self::DICE_COUNT);
    }

    private function canRollAgainValue(): bool
    {
        if ($this->dice === [] || $this->gameOver || $this->isSpectatorValue() || ! $this->isMyTurnValue()) {
            return false;
        }

        $selection = $this->selectionScoreValue();

        return $selection['valid'] && $selection['score'] > 0;
    }

    public function getCanRollAgainProperty(): bool
    {
        return $this->canRollAgainValue();
    }

    /**
     * What clicking Bank right now would actually add to the player's
     * banked score -- every scoring die in the current roll, auto-
     * included regardless of manual clicks (see bank()'s own docblock).
     * Used both for canBank's own gating and the button's on-screen
     * label, so the two can never disagree with each other.
     */
    private function bankableAmountValue(): int
    {
        return $this->turnScore + $this->engine->scoreMaximal($this->dice, $this->activeDieSpicy());
    }

    public function getBankableAmountProperty(): int
    {
        return $this->bankableAmountValue();
    }

    private function canBankValue(): bool
    {
        if ($this->dice === [] || $this->gameOver || $this->isSpectatorValue() || ! $this->isMyTurnValue()) {
            return false;
        }

        if ($this->engine->scoreMaximal($this->dice, $this->activeDieSpicy()) <= 0) {
            return false;
        }

        $player = $this->players[$this->currentPlayer];

        return $this->engine->meetsMinimumEntry($player['hasQualified'], $this->bankableAmountValue());
    }

    public function getCanBankProperty(): bool
    {
        return $this->canBankValue();
    }

    /**
     * Whether the High Stakes choice (docs/specs/farkle.md §6.5) should
     * be offered right now instead of the plain Roll button -- only at
     * the true start of a turn (nothing rolled or held yet this turn),
     * with High Stakes on and something actually left to inherit from
     * the previous turn's bank.
     */
    private function canTakeOverValue(): bool
    {
        if ($this->dice !== [] || $this->gameOver || $this->isSpectatorValue() || ! $this->isMyTurnValue()) {
            return false;
        }

        return $this->highStakesEnabled
            && $this->lastBankInheritance !== null
            && $this->bankedDiceThisTurn === [];
    }

    public function getCanTakeOverProperty(): bool
    {
        return $this->canTakeOverValue();
    }

    public function getCanEndGameManuallyProperty(): bool
    {
        if ($this->gameOver) {
            return false;
        }

        return $this->room === null || Auth::id() === $this->resolveRoom()->host_user_id;
    }

    /**
     * See pages::yahtzee.play's own getIsSpectatorProperty() docblock --
     * identical meaning.
     */
    private function isSpectatorValue(): bool
    {
        return $this->room !== null && ($this->mySeatIndex === null || $this->spectate);
    }

    public function getIsSpectatorProperty(): bool
    {
        return $this->isSpectatorValue();
    }

    private function isMyTurnValue(): bool
    {
        return $this->room === null || $this->mySeatIndex === $this->currentPlayer;
    }

    public function getIsMyTurnProperty(): bool
    {
        return $this->isMyTurnValue();
    }

    public function getStatusTextProperty(): string
    {
        if ($this->gameOver) {
            return $this->message ?? '';
        }

        $name = $this->players[$this->currentPlayer]['name'];
        $turnScoreText = $this->turnScore > 0 ? ", turn score {$this->turnScore}" : '';

        return "{$name}'s turn{$turnScoreText}";
    }
};
?>

<x-slot:title>10,000 — Low Stim Play</x-slot>
<x-slot:description>A full 10,000 scorecard for 1-8 players, with hot dice and a configurable final round, in a calm, low-contrast design.</x-slot>
<x-slot:bodyClass>farkle-play</x-slot:bodyClass>
<x-slot:fullscreenToggle>1</x-slot:fullscreenToggle>
<x-slot:rulesTitle>10,000 Rules</x-slot:rulesTitle>
<x-slot:rules>
    <h3>How to Use This Page</h3>
    <ul>
        <li>Set the number of players (1&ndash;8) and optional names, plus the target score and final-round option, then press Start Game.</li>
        <li>On your turn, press Roll. Bank ends your turn and keeps every scoring die's points automatically &mdash; you don't have to tap each one first.</li>
        <li>Only tap individual dice if you want to lock some in and Roll Again for the rest &mdash; only dice that actually score can be selected that way.</li>
        <li>A roll with no scoring dice at all is a bust &mdash; the turn ends immediately and everything banked so far this turn is lost.</li>
    </ul>

    <h3>Full Rules</h3>
    <ul>
        <li>Each turn, roll all 6 dice. Bank ends your turn, automatically adding up every scoring die in the current roll (whether or not you've tapped it) &mdash; or tap specific dice and press Roll Again to lock just those in and reroll the rest.</li>
        <li>A single 1 scores 100; a single 5 scores 50.</li>
        <li>Three of a kind scores by face value: three 1s = 300, three of any other number <em>n</em> = <em>n</em> &times; 100 (e.g. three 4s = 400).</li>
        <li>Four of a kind = 1000, five of a kind = 2000, six of a kind = 3000, regardless of which number is repeated &mdash; whichever way of scoring a roll is worth more is always used automatically.</li>
        <li>A straight (one each of 1&ndash;6) scores 1500. Three pairs (e.g. 2-2, 4-4, 6-6) scores 1500. Two separate triplets (e.g. three 2s and three 5s) scores 2500 &mdash; these three all use every one of the 6 dice in a single roll.</li>
        <li>If all 6 dice have scored during a turn (across one or more rolls), you get "hot dice" &mdash; roll all 6 again and keep adding to the same turn's total.</li>
        <li>A roll with no scoring dice at all is a bust: the turn ends immediately and everything banked so far <em>this turn</em> is lost (points already banked from earlier turns are safe).</li>
        <li>Your first banked turn must total at least 500 points to get on the board; every turn after that banks normally, however small.</li>
        <li>The game plays to a target score (10,000 by default), shown at the top of the page once it starts. With the final round option on, once someone banks a turn that reaches the target, every other player gets exactly one more turn (announced on screen) before the game ends and the highest total wins (ties are possible). With it off, the game ends the instant anyone reaches the target.</li>
        <li>A host (or, in local pass-and-play, any player) can end the game early from the button bar instead of playing to the target.</li>
    </ul>

    <h3>Spicy Variant (optional)</h3>
    <ul>
        <li>Each of the 6 dice has one number, 1&ndash;6, marked as its own "spicy" face &mdash; shown with a highlighted border whenever a die lands on its own number.</li>
        <li>A spicy die doubles the score of whatever combination it's part of (e.g. three 4s with one spicy 4 scores 800, not 400). Multiple spicy dice in the same combination double it again each time.</li>
        <li>Rolling all 6 dice spicy at once is an instant win, ending the game immediately for everyone regardless of anyone's current score.</li>
        <li>Spicy games default to a 25,000 target score instead of 10,000, still adjustable at setup.</li>
    </ul>

    <h3>High Stakes Variant (optional, independent of Spicy)</h3>
    <ul>
        <li>Whenever the previous player ends their turn by banking, the next player can choose to take over instead of rolling a fresh six: their turn starts with the previous player's <em>entire</em> banked turn score already as their own turn score, plus however many dice that player hadn't held yet (freshly rerolled, not the same values).</li>
        <li>That head start is now fully at risk &mdash; busting this turn loses all of it, same as any other turn score. It never reduces the previous player's own total either way.</li>
        <li>Only available right after a bank, never after a bust, and only for the very next player &mdash; declining it (rolling fresh instead) forfeits it for good.</li>
    </ul>
</x-slot:rules>
<div class="flex flex-col items-center w-full flex-1 min-h-0">
    @if ($started)
        @teleport('#game-actions')
            <div style="display: contents;">
                @if ($room !== null && ! $this->isSpectator)
                    <button type="button" id="scores-btn" onclick="document.getElementById('scoreboard-drawer').showModal()">Scores</button>
                @endif

                @if ($room !== null && $mySeatIndex !== null)
                    <button type="button" wire:click="$set('spectate', {{ $this->isSpectator ? 'false' : 'true' }})">
                        {{ $this->isSpectator ? 'Player view' : 'Watch only' }}
                    </button>
                @endif

                @if ($this->canEndGameManually)
                    <button type="button" id="end-game-trigger" data-dialog-open="end-game-confirm-dialog">End Game</button>

                    <x-confirm-dialog id="end-game-confirm-dialog" title="End the game?"
                        message="End the game now and show final scores?">
                        <x-slot:confirm>
                            <button type="button" id="end-game-confirm" data-dialog-close wire:click="endGameManually"
                                class="flex-1 rounded px-6 py-2 font-bold tracking-wide bg-surface border-2 border-warn text-warn">
                                End Game
                            </button>
                        </x-slot:confirm>
                    </x-confirm-dialog>
                @endif

                @if ($room === null)
                    <button type="button" wire:click="newGame">New Game</button>
                @elseif ($gameOver)
                    <a href="{{ route('room.show', ['code' => $room]) }}"><button type="button">Back to room</button></a>
                @endif
            </div>
        @endteleport
    @endif

    @unless ($started)
        <div class="setup" id="setup">
            <div class="heading">10,000</div>

            @if ($this->hostedRooms->isNotEmpty())
                <div class="flex flex-col items-center gap-2 w-full">
                    <p class="status-line">Play in a room you host:</p>
                    <div class="flex flex-wrap justify-center gap-2">
                        @foreach ($this->hostedRooms as $hostedRoom)
                            <a href="{{ route('farkle.room', ['code' => $hostedRoom->code]) }}" wire:key="hosted-room-{{ $hostedRoom->id }}">
                                <button type="button" id="hosted-room-{{ $hostedRoom->code }}">{{ $hostedRoom->code }}</button>
                            </a>
                        @endforeach
                    </div>
                </div>

                <div class="status-line">or play locally:</div>
            @endif

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

            <div class="setup-row">
                <div class="label">Target Score</div>
                <div class="option-group">
                    @foreach ($spicyEnabled ? self::SPICY_TARGET_SCORE_OPTIONS : self::TARGET_SCORE_OPTIONS as $option)
                        <button type="button" id="target-score-{{ $option }}" class="option {{ $targetScore === $option ? 'active' : '' }}" wire:click="$set('targetScore', {{ $option }})">
                            {{ number_format($option) }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="setup-row">
                <div class="label">Final Round</div>
                <div class="option-group">
                    <button type="button" id="final-round-on" class="option {{ $withFinalRound ? 'active' : '' }}" wire:click="$set('withFinalRound', true)">On</button>
                    <button type="button" id="final-round-off" class="option {{ ! $withFinalRound ? 'active' : '' }}" wire:click="$set('withFinalRound', false)">Off</button>
                </div>
            </div>

            <div class="setup-row">
                <div class="label">Spicy</div>
                <div class="option-group">
                    <button type="button" id="spicy-on" class="option {{ $spicyEnabled ? 'active' : '' }}" wire:click="setSpicyEnabled(true)">On</button>
                    <button type="button" id="spicy-off" class="option {{ ! $spicyEnabled ? 'active' : '' }}" wire:click="setSpicyEnabled(false)">Off</button>
                </div>
            </div>

            <div class="setup-row">
                <div class="label">High Stakes</div>
                <div class="option-group">
                    <button type="button" id="high-stakes-on" class="option {{ $highStakesEnabled ? 'active' : '' }}" wire:click="$set('highStakesEnabled', true)">On</button>
                    <button type="button" id="high-stakes-off" class="option {{ ! $highStakesEnabled ? 'active' : '' }}" wire:click="$set('highStakesEnabled', false)">Off</button>
                </div>
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
        <div id="farkle-game" class="farkle-play">
            <div class="farkle-target-line">
                Playing to {{ number_format($targetScore) }}{{ $withFinalRound ? '' : ' — first to reach it wins immediately' }}
            </div>

            @if ($finalRoundActive && ! $gameOver)
                <div class="farkle-final-round-banner">Final Round!</div>
            @endif

            <div class="status">{{ $this->statusText }}</div>

            {{--
                Always rendered, fixed min-height (see app.css) -- the
                previous version only rendered this <p> when a message
                existed, so its own min-height never applied and the
                whole page visibly jumped every time a message appeared
                or disappeared (docs/concerns.md). Wrapping it in an
                always-present container instead means the reserved space
                never changes, whether or not there's currently a message
                inside it.
            --}}
            <div class="farkle-message-slot">
                @if ($message && ! $gameOver)
                    <p class="status-line {{ $messageType === 'bust' ? 'farkle-message-bust' : '' }}">{{ $message }}</p>
                @endif
            </div>

            {{--
                farkle-game-area/-dice-column exist purely for landscape
                mobile (see app.css): plain display: contents everywhere
                else, so they're invisible to layout and change nothing by
                default -- only the landscape media query turns them into
                real flex containers, putting the dice column and
                scoreboard side by side so the scoreboard gets far more
                usable height than the single stacked column below it ever
                could on a short landscape viewport (docs/concerns.md).
            --}}
            <div class="farkle-game-area">
                @unless ($gameOver)
                    <div class="farkle-dice-column">
                        <div class="dice-area">
                            <div class="row">
                                @for ($i = 0; $i < self::DICE_COUNT - $heldDiceCount; $i++)
                                    <div class="die {{ ($selected[$i] ?? false) ? 'selected' : '' }} {{ ($dieSpicy[$i] ?? false) ? 'spicy' : '' }}" @if ($this->isMyTurn && ! $this->isSpectator && $dice !== []) wire:click="toggleSelect({{ $i }})" @endif>
                                        <span class="number">{{ $dice[$i] ?? '' }}</span>
                                    </div>
                                @endfor
                            </div>

                            <div class="controls">
                                @if ($this->isMyTurn && ! $this->isSpectator)
                                    @if ($dice === [])
                                        @if ($this->canTakeOver)
                                            <button type="button" id="rollFreshBtn" wire:click="roll">Roll Fresh Six</button>
                                            <button type="button" id="rollTakeOverBtn" wire:click="rollTakeOver">
                                                Take Over ({{ number_format($lastBankInheritance['turnScore']) }} + {{ count($lastBankInheritance['remainingSlots']) }} dice)
                                            </button>
                                        @else
                                            <button type="button" id="rollBtn" wire:click="roll" @disabled($gameOver)>Roll</button>
                                        @endif
                                    @else
                                        <button type="button" id="rollAgainBtn" wire:click="rollAgain" @disabled(! $this->canRollAgain)>Roll Again</button>
                                        <button type="button" id="bankBtn" wire:click="bank" @disabled(! $this->canBank)>Bank {{ $this->bankableAmount }}</button>
                                    @endif
                                @endif
                            </div>
                        </div>

                        {{--
                            Dice already locked in earlier this same turn
                            (across however many rolls) -- shown below the
                            active roll, one row per roll, rather than
                            silently vanishing the moment they're set aside
                            (docs/concerns.md).
                        --}}
                        @if ($this->bankedDiceRows !== [])
                            <div class="farkle-banked-rows">
                                @foreach ($this->bankedDiceRows as $rowIndex => $row)
                                    <div class="farkle-banked-row" wire:key="banked-row-{{ $rowIndex }}">
                                        @foreach ($row as $i => $value)
                                            <div class="die banked" wire:key="banked-{{ $rowIndex }}-{{ $i }}">
                                                <span class="number">{{ $value }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endunless

                @if ($this->isSpectator && ! $gameOver)
                    {{--
                        docs/concerns.md's "advancing from one game to the
                        next" item generalizes Clear the Deck's own
                        no-scrolling-while-spectating fix to every game with
                        a central spectator view -- Farkle's scoreboard
                        below is a single tall column with a fixed
                        min-height/overflow-y-auto, built for a seated
                        player's own device (where scrolling to see the
                        rest of the field is a normal, established
                        interaction), but for a TV-cast/screen-share
                        spectator that meant several players scrolled out
                        of view entirely. This copy wraps into a grid
                        instead, sized via container query units (cqh) --
                        same technique Yahtzee's own scorecard already uses
                        (see app.css) -- so name/score text shrinks to
                        whatever fits .farkle-scoreboard's own actual
                        rendered height rather than needing to scroll past
                        it, regardless of player count.
                    --}}
                    <div class="farkle-scoreboard w-full bg-surface border-2 border-surface-border rounded-lg farkle-scoreboard-spectator">
                        <div class="farkle-spectator-grid">
                            @foreach ($players as $i => $player)
                                <div wire:key="spectator-score-{{ $i }}" class="farkle-spectator-card {{ $i === $currentPlayer ? 'farkle-spectator-card-active' : '' }}">
                                    <span class="farkle-spectator-name">{{ $player['name'] }}{{ $i === $currentPlayer ? ' (current)' : '' }}</span>
                                    <span class="farkle-spectator-score">{{ $player['bankedScore'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="farkle-scoreboard w-full bg-surface border-2 border-surface-border rounded-lg overflow-y-auto overflow-x-hidden {{ $gameOver ? 'farkle-final-scores' : '' }}">
                        <div class="grid" style="grid-template-columns: 1fr auto;">
                            @foreach ($players as $i => $player)
                                <span wire:key="name-{{ $i }}" class="pl-6 pr-3 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} {{ $gameOver ? '' : 'text-sm' }} {{ $i === $currentPlayer && ! $gameOver ? 'font-bold text-text' : 'text-text/80' }}">
                                    {{ $player['name'] }}{{ $i === $currentPlayer && ! $gameOver ? ' (current)' : '' }}
                                </span>
                                <span wire:key="score-{{ $i }}" class="pl-3 pr-6 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} {{ $gameOver ? 'font-bold' : 'text-sm' }} text-text text-right">
                                    {{ $player['bankedScore'] }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{--
            Mirrors pages::yahtzee.play's own scoreboard-drawer exactly --
            same wire:ignore.self reasoning (a <dialog>'s open state lives
            only in the DOM, so it needs protecting from a Livewire morph
            wiping it out from under whoever has it open).
        --}}
        @if ($room !== null && ! $this->isSpectator)
            <dialog wire:ignore.self class="scoreboard-dialog" id="scoreboard-drawer" aria-labelledby="scoreboard-drawer-title" tabindex="-1">
                <div class="rules-dialog-header">
                    <div class="rules-dialog-title" id="scoreboard-drawer-title">Scores</div>
                    <button type="button" class="rules-dialog-close" aria-label="Close" onclick="document.getElementById('scoreboard-drawer').close()">&times;</button>
                </div>

                <div class="status">{{ $this->statusText }}</div>

                <div class="w-full bg-surface border-2 border-surface-border rounded-lg overflow-hidden">
                    <div class="grid" style="grid-template-columns: 1fr auto;">
                        @foreach ($players as $i => $player)
                            <span wire:key="drawer-name-{{ $i }}" class="pl-6 pr-3 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm {{ $i === $currentPlayer ? 'font-bold text-text' : 'text-text/80' }}">
                                {{ $player['name'] }}{{ $i === $currentPlayer ? ' (current)' : '' }}
                            </span>
                            <span wire:key="drawer-score-{{ $i }}" class="pl-3 pr-6 py-3 {{ $loop->first ? '' : 'border-t border-surface-border' }} text-sm text-text text-right">
                                {{ $player['bankedScore'] }}
                            </span>
                        @endforeach
                    </div>
                </div>
            </dialog>
        @endif
    @endunless
</div>

@script
<script>
    // Same reasoning as pages::yahtzee.play's own identical script.
    document.body.classList.toggle('in-game', $wire.started);
    $wire.$watch('started', (started) => {
        document.body.classList.toggle('in-game', started);
    });

    if ($wire.room !== null) {
        document.querySelector('.button-bar')?.classList.add('many-buttons');
    }
</script>
@endscript

