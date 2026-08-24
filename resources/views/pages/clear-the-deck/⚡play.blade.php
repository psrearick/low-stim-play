<?php

use App\Enums\ClearTheDeckPhase;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Events\RoomGameStateUpdated;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    /**
     * Online room this play-through belongs to -- Clear the Deck has no
     * pass-and-play mode (see docs/specs/clear-the-deck.md section 1), same
     * reasoning as pages::apple-match.play's own $room.
     */
    #[Url]
    public ?string $room = null;

    /**
     * A seated player's own explicit choice to view this device read-only
     * anyway -- for casting to a TV or screen-sharing, mirrors pages::
     * yahtzee.play's own $spectate exactly (see its docblock for the full
     * two-devices-same-account reasoning). Purely a per-device URL flag,
     * not account-wide state.
     */
    #[Url]
    public bool $spectate = false;

    public bool $started = false;

    public ?int $gameSessionId = null;

    public int $roundTarget = 1;

    public int $currentRound = 1;

    /**
     * @var list<array{seatIndex: int, name: string}>
     */
    public array $seats = [];

    /**
     * Cumulative points across every round played so far -- lower is
     * better, unlike every other game's own score.
     *
     * @var array<int, int>
     */
    public array $scores = [];

    /**
     * seatIndex => rounds gone out first in, for stats only.
     *
     * @var array<int, int>
     */
    public array $roundsWonCounts = [];

    public int $dealerSeatIndex = 0;

    public int $turnSeatIndex = 0;

    /**
     * Every card currently in the shared center pile, in play order (last
     * entry = current top, the value the next play must match or beat
     * under). Picking it up takes the whole thing, not just the top card,
     * so the full list -- not just the top value -- has to be kept.
     *
     * @var list<int>
     */
    public array $centerStack = [];

    public ClearTheDeckPhase $phase = ClearTheDeckPhase::Playing;

    public ?string $lastMessage = null;

    /**
     * RoundEnd only: this round's own point total per seat, cleared by
     * nextRound().
     *
     * @var array<int, int>
     */
    public array $roundScores = [];

    public ?int $wentOutSeatIndex = null;

    public ?string $winnerName = null;

    /**
     * Public per-seat table info -- hand *count* (never contents), face-up
     * card *values* (public even for other seats, see appendix), and
     * face-down *count* (never contents/values -- see class-level notes on
     * playCards() below for why).
     *
     * @var array<int, array{handCount: int, tableFaceUp: list<int>, tableFaceDownCount: int}>
     */
    public array $seatTables = [];

    /**
     * This connection's seat, or null for an unseated spectator.
     */
    public ?int $mySeatIndex = null;

    /**
     * This seat's own private hand -- loaded fresh from RoomSeat::$hand
     * (never from $state; see class-level notes below).
     *
     * @var list<int>
     */
    public array $myHand = [];

    /**
     * @var list<int>
     */
    public array $selectedHandIndices = [];

    /**
     * @var list<int>
     */
    public array $selectedFaceUpIndices = [];

    /**
     * Display-indices (0-based among currently-uncovered face-down
     * columns, see columnsFor() below) the player has individually
     * toggled -- see toggleFaceDownSelection() for why this is a set, not
     * a scalar count.
     *
     * @var list<int>
     */
    public array $selectedFaceDownColumns = [];

    /**
     * Set once a submitted play includes a face-down flip that turned out
     * to be a real, playable value -- "once revealed, that card must be
     * played" (appendix), but what else joins it is the player's own
     * choice, made *after* seeing the value. Only ever set when there's
     * at least one other matching card available to offer; otherwise the
     * play just finishes immediately, same as one with no face-down cards
     * in it at all. See playCards() for the full flow.
     */
    public ?int $pendingFlipValue = null;

    public ?string $playError = null;

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
        if ($session === null || $session->type !== GameType::ClearTheDeck) {
            $this->redirect(route('room.show', ['code' => $roomModel->code]));

            return;
        }

        $this->gameSessionId = $session->id;
        $this->hydrateFromState($session->state);

        $seat = RoomSeat::where('room_id', $roomModel->id)->where('user_id', Auth::id())->first();
        $this->mySeatIndex = $seat?->seat_index;
        $this->myHand = $seat->hand ?? [];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function hydrateFromState(array $state): void
    {
        $this->roundTarget = $state['roundTarget'];
        $this->currentRound = $state['currentRound'];
        $this->seats = $state['seats'];
        $this->scores = $state['scores'];
        $this->roundsWonCounts = $state['roundsWonCounts'];
        $this->dealerSeatIndex = $state['dealerSeatIndex'];
        $this->turnSeatIndex = $state['turnSeatIndex'];
        $this->centerStack = $state['centerStack'];
        $this->phase = ClearTheDeckPhase::from($state['phase']);
        $this->lastMessage = $state['lastMessage'];
        $this->roundScores = $state['roundScores'];
        $this->wentOutSeatIndex = $state['wentOutSeatIndex'];
        $this->winnerName = $state['winnerName'];
        $this->seatTables = $state['seatTables'];
        $this->started = true;
    }

    private function resolveRoom(): Room
    {
        return Room::where('code', $this->room)->firstOrFail();
    }

    /**
     * Persists + broadcasts the shared game state. Deliberately never
     * includes hand contents or face-down identities -- see playCards()'s
     * own note. Refreshes $seatTables from the database first so every
     * seat's public hand-count/table info is always current, without every
     * call site needing to remember to do it separately.
     */
    private function syncOnlineState(): void
    {
        $this->refreshSeatTables();

        $state = [
            'roundTarget' => $this->roundTarget,
            'currentRound' => $this->currentRound,
            'seats' => $this->seats,
            'scores' => $this->scores,
            'roundsWonCounts' => $this->roundsWonCounts,
            'dealerSeatIndex' => $this->dealerSeatIndex,
            'turnSeatIndex' => $this->turnSeatIndex,
            'centerStack' => $this->centerStack,
            'phase' => $this->phase->value,
            'lastMessage' => $this->lastMessage,
            'roundScores' => $this->roundScores,
            'wentOutSeatIndex' => $this->wentOutSeatIndex,
            'winnerName' => $this->winnerName,
            'seatTables' => $this->seatTables,
        ];

        DB::transaction(function () use ($state) {
            GameSession::where('id', $this->gameSessionId)->lockForUpdate()->first();
            GameSession::find($this->gameSessionId)->update(['state' => $state]);
        });

        RoomGameStateUpdated::dispatch($this->resolveRoom(), $state);
    }

    private function refreshSeatTables(): void
    {
        $roomSeats = RoomSeat::where('room_id', $this->resolveRoom()->id)->get()->keyBy('seat_index');

        $this->seatTables = collect($this->seats)->mapWithKeys(function (array $seat) use ($roomSeats) {
            $roomSeat = $roomSeats[$seat['seatIndex']] ?? null;

            return [$seat['seatIndex'] => [
                'handCount' => count($roomSeat->hand ?? []),
                'tableFaceUp' => $roomSeat->table_face_up ?? [],
                'tableFaceDownCount' => count($roomSeat->table_face_down ?? []),
            ]];
        })->all();
    }

    /**
     * @param  array{state: array<string, mixed>}  $event
     */
    public function onStateUpdated(array $event): void
    {
        $this->hydrateFromState($event['state']);
        $this->resetSelection();

        if ($this->mySeatIndex !== null) {
            $seat = RoomSeat::where('room_id', $this->resolveRoom()->id)
                ->where('seat_index', $this->mySeatIndex)
                ->first();
            $this->myHand = $seat->hand ?? [];
        }
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

    public function getIsHostProperty(): bool
    {
        return $this->room !== null && Auth::id() === $this->resolveRoom()->host_user_id;
    }

    /**
     * Mirrors pages::farkle.play's own getCanEndGameManuallyProperty()
     * (docs/concerns.md) -- host-only, and only while there's still a game
     * to end.
     */
    public function getCanEndGameManuallyProperty(): bool
    {
        return $this->phase !== ClearTheDeckPhase::GameEnd && $this->isHost;
    }

    /**
     * Deliberately independent of $spectate -- this still has to say
     * "yes" for playCards()'s own abort_unless() to keep working from a
     * casting device, same reasoning as pages::yahtzee.play's own
     * isMyTurn. $spectate only ever affects what this device *shows* (see
     * isSpectator below and the Blade's own !$this->isSpectator display
     * guards), never who's actually allowed to act.
     */
    private function isMyTurnValue(): bool
    {
        return $this->mySeatIndex !== null
            && $this->phase === ClearTheDeckPhase::Playing
            && $this->mySeatIndex === $this->turnSeatIndex;
    }

    public function getIsMyTurnProperty(): bool
    {
        return $this->isMyTurnValue();
    }

    /**
     * True for a genuinely unseated connection, or for a seated player
     * who's explicitly opted into read-only viewing anyway ($spectate --
     * see its own docblock). Drives the read-only "every seat's tableau,
     * larger, no hand/submit" view in the Blade below.
     */
    public function getIsSpectatorProperty(): bool
    {
        return $this->room !== null && ($this->mySeatIndex === null || $this->spectate);
    }

    /**
     * @return list<int>
     */
    public function getMyTableFaceUpProperty(): array
    {
        return $this->seatTables[$this->mySeatIndex]['tableFaceUp'] ?? [];
    }

    public function getMyUncoveredFaceDownCountProperty(): int
    {
        if ($this->mySeatIndex === null) {
            return 0;
        }

        $tableFaceDownCount = $this->seatTables[$this->mySeatIndex]['tableFaceDownCount'] ?? 0;

        return max(0, $tableFaceDownCount - count($this->myTableFaceUp));
    }

    /**
     * While a flip is pending (its value already revealed to this
     * connection), only cards matching that revealed value can be added --
     * anything else is a no-op, the server-side half of the "grayed out"
     * cards the template renders as unclickable.
     */
    public function toggleHandCard(int $index): void
    {
        if ($this->pendingFlipValue !== null && ($this->myHand[$index] ?? null) !== $this->pendingFlipValue) {
            return;
        }

        $this->toggleSelection('selectedHandIndices', $index);
    }

    public function toggleFaceUpCard(int $index): void
    {
        if ($this->pendingFlipValue !== null && ($this->myTableFaceUp[$index] ?? null) !== $this->pendingFlipValue) {
            return;
        }

        $this->toggleSelection('selectedFaceUpIndices', $index);
    }

    /**
     * @param  'selectedHandIndices'|'selectedFaceUpIndices'|'selectedFaceDownColumns'  $property
     */
    private function toggleSelection(string $property, int $index): void
    {
        $this->$property = in_array($index, $this->$property, true)
            ? array_values(array_diff($this->$property, [$index]))
            : [...$this->$property, $index];
    }

    /**
     * Each face-down tile toggles independently, exactly like a hand or
     * face-up card -- tapping one does not select any of the others. The
     * specific tile tapped only drives this connection's own highlight
     * state; the cards themselves are still interchangeable server-side
     * (see playCards()), so all that actually reaches the server is how
     * many are selected. Once a flip is pending, the only thing left to do
     * with a face-down tile is back out of it entirely (untoggling it
     * clears the pending reveal) -- swapping in a *different* blind flip
     * mid-reveal isn't offered.
     */
    public function toggleFaceDownSelection(int $displayIndex): void
    {
        if ($this->pendingFlipValue !== null) {
            if (in_array($displayIndex, $this->selectedFaceDownColumns, true)) {
                $this->selectedFaceDownColumns = array_values(array_diff($this->selectedFaceDownColumns, [$displayIndex]));
                $this->pendingFlipValue = null;
            }

            return;
        }

        $this->toggleSelection('selectedFaceDownColumns', $displayIndex);
    }

    /**
     * Builds up to 4 display "columns" (a face-down card peeking behind a
     * face-up card, or a lone uncovered face-down back) from a seat's
     * public face-up list and face-down count -- purely a presentation
     * layer over the aggregate-bag model in docs/specs/clear-the-deck.md
     * section 4, which never tracks which face-down card was originally
     * dealt under which face-up card since that pairing never actually
     * matters. Face-up columns keep their real array index (for
     * toggleFaceUpCard()); face-down-only columns are numbered separately,
     * 0-based among just the uncovered ones (for
     * toggleFaceDownSelection()).
     *
     * @param  list<int>  $faceUp
     * @return list<array{type: 'faceUp'|'faceDown', index: int, value: int|null, hasFaceDown: bool}>
     */
    private function columnsFor(array $faceUp, int $faceDownCount): array
    {
        $covered = min(count($faceUp), $faceDownCount);
        $uncovered = max(0, $faceDownCount - count($faceUp));

        $columns = [];

        foreach ($faceUp as $i => $value) {
            $columns[] = ['type' => 'faceUp', 'index' => $i, 'value' => $value, 'hasFaceDown' => $i < $covered];
        }

        for ($i = 0; $i < $uncovered; $i++) {
            $columns[] = ['type' => 'faceDown', 'index' => $i, 'value' => null, 'hasFaceDown' => true];
        }

        // Always reserve 4 column slots (the number every seat starts a
        // round with) so a seat's tableau stays the same width even once
        // some of its columns are fully played out, keeping every player's
        // tableau aligned the same way the design mockup's own
        // TableauColumn does (it always renders a column wrapper, even an
        // empty one).
        while (count($columns) < 4) {
            $columns[] = ['type' => 'empty', 'index' => count($columns), 'value' => null, 'hasFaceDown' => false];
        }

        return $columns;
    }

    /**
     * @return list<array{type: string, index: int, value: int|null, hasFaceDown: bool}>
     */
    public function getMyColumnsProperty(): array
    {
        if ($this->mySeatIndex === null) {
            return [];
        }

        return $this->columnsFor($this->myTableFaceUp, $this->seatTables[$this->mySeatIndex]['tableFaceDownCount'] ?? 0);
    }

    /**
     * @return list<array{type: string, index: int, value: int|null, hasFaceDown: bool}>
     */
    public function columnsForSeat(int $seatIndex): array
    {
        $table = $this->seatTables[$seatIndex] ?? ['tableFaceUp' => [], 'tableFaceDownCount' => 0];

        return $this->columnsFor($table['tableFaceUp'], $table['tableFaceDownCount']);
    }

    private function resetSelection(): void
    {
        $this->selectedHandIndices = [];
        $this->selectedFaceUpIndices = [];
        $this->selectedFaceDownColumns = [];
        $this->pendingFlipValue = null;
        $this->playError = null;
    }

    /**
     * Attempts the currently-selected play. A face-down selection is a set
     * of tiles, not specific cards, and is validated atomically along with
     * everything else -- if the combined values (including whatever the
     * face-down cards turn out to be) don't all match, the whole attempt is
     * rejected with no state change and nothing about the face-down cards'
     * values ever reaching this response. table_face_down is read fresh
     * from the database right here and never assigned to any public
     * property that gets broadcast -- the same "resolve secret state fresh
     * from the DB inside the one action that needs it" rule Best Match's
     * round attribution established (docs/DEVLOG.md's Best Match section),
     * for this app's first "secret from its own owner" state.
     *
     * If the combination *is* valid and includes a face-down flip, this
     * only reveals the value to the acting player and pauses (via
     * $pendingFlipValue) rather than committing immediately, as long as
     * there's at least one other matching card available to optionally
     * add -- calling this a second time, with $pendingFlipValue already
     * set, finalizes the play with whatever's selected at that point. See
     * docs/specs/clear-the-deck.md section 3/4.
     */
    public function playCards(): void
    {
        abort_unless($this->isMyTurnValue(), 403);

        $seat = RoomSeat::where('room_id', $this->resolveRoom()->id)
            ->where('seat_index', $this->mySeatIndex)
            ->first();

        $hand = $seat->hand ?? [];
        $faceUp = $seat->table_face_up ?? [];
        $faceDown = $seat->table_face_down ?? [];

        $handIndices = array_values(array_unique($this->selectedHandIndices));
        $faceUpIndices = array_values(array_unique($this->selectedFaceUpIndices));
        $faceDownCount = count($this->selectedFaceDownColumns);

        $uncoveredFaceDown = max(0, count($faceDown) - count($faceUp));

        $indicesValid = collect($handIndices)->every(fn (int $i) => array_key_exists($i, $hand))
            && collect($faceUpIndices)->every(fn (int $i) => array_key_exists($i, $faceUp))
            && $faceDownCount >= 0 && $faceDownCount <= $uncoveredFaceDown;

        if (! $indicesValid) {
            $this->playError = 'That selection is no longer valid.';

            return;
        }

        $handValues = collect($handIndices)->map(fn (int $i) => $hand[$i])->all();
        $faceUpValues = collect($faceUpIndices)->map(fn (int $i) => $faceUp[$i])->all();

        // Face-down cards are an unordered bag -- any $faceDownCount of them
        // is equivalent to "the currently uncovered ones," since none are
        // individually distinguishable before being played. See
        // docs/specs/clear-the-deck.md section 4.
        $faceDownValues = array_slice($faceDown, 0, $faceDownCount);

        $playedValues = [...$handValues, ...$faceUpValues, ...$faceDownValues];

        if ($playedValues === []) {
            $this->playError = 'Select at least one card to play.';

            return;
        }

        $value = $playedValues[0];

        if (! collect($playedValues)->every(fn (int $v) => $v === $value)) {
            $this->resetSelection();
            $this->playError = "That combination doesn't share the same value.";

            return;
        }

        // Valid so far. If this includes a not-yet-revealed face-down flip,
        // offer to add more before committing -- but only once, and only
        // when there's actually something left to offer. A second call
        // (with $pendingFlipValue already set) always falls through to
        // commit, whatever's currently selected.
        if ($faceDownCount > 0 && $this->pendingFlipValue === null) {
            $remainingHandMatches = collect($hand)->filter(
                fn (int $v, int $i) => $v === $value && ! in_array($i, $handIndices, true)
            )->count();
            $remainingFaceUpMatches = collect($faceUp)->filter(
                fn (int $v, int $i) => $v === $value && ! in_array($i, $faceUpIndices, true)
            )->count();

            if ($remainingHandMatches + $remainingFaceUpMatches > 0) {
                $this->pendingFlipValue = $value;
                $this->playError = null;

                return;
            }
        }

        $seat->hand = array_values(array_diff_key($hand, array_flip($handIndices)));
        $seat->table_face_up = array_values(array_diff_key($faceUp, array_flip($faceUpIndices)));
        $seat->table_face_down = array_slice($faceDown, $faceDownCount);
        $seat->save();

        $this->resetSelection();
        $this->resolvePlay($seat, $value, $playedValues);
    }

    /**
     * @param  list<int>  $playedValues
     */
    private function resolvePlay(RoomSeat $seat, int $value, array $playedValues): void
    {
        $seatIndex = $seat->seat_index;
        $name = $this->seatName($seatIndex);
        $label = self::cardLabel($value);
        $count = count($playedValues);

        if ($value === 10) {
            $this->centerStack = [];
            $this->lastMessage = "{$name} played the Wild Ten and cleared the deck!";
            $this->finishTurnWithExtraTurn();

            return;
        }

        $runLength = $this->trailingRunLength($value) + $count;

        if ($runLength >= 4) {
            $this->centerStack = [];
            $this->lastMessage = "{$name} cleared the deck with four {$label}s!";
            $this->finishTurnWithExtraTurn();

            return;
        }

        $topValue = $this->centerStack === [] ? null : end($this->centerStack);

        if ($topValue !== null && $value > $topValue) {
            $pickedUp = [...$this->centerStack, ...$playedValues];
            $seat->hand = [...($seat->hand ?? []), ...$pickedUp];
            $seat->save();
            $this->centerStack = [];
            $this->lastMessage = "{$name} played too high and picked up ".count($pickedUp).' cards.';
            // Ends the turn immediately, same as an ordinary non-set-
            // completing play below -- docs/TODO.md's former #21, a
            // deliberate rule change from the original design (a pickup
            // used to grant an extra turn alongside the Wild Ten/completed-
            // run cases above). A pickup always adds cards to the seat's
            // own hand, so it can never itself trigger hasGoneOut() --
            // finishTurnAdvancingToNextSeat()'s own check is still run for
            // consistency with the identical call below, not because this
            // path can actually reach it.
            $this->finishTurnAdvancingToNextSeat($seat, $seatIndex);

            return;
        }

        $this->centerStack = [...$this->centerStack, ...$playedValues];
        $this->lastMessage = "{$name} played {$count} \u{d7} {$label}".($count > 1 ? 's' : '').'.';
        $this->finishTurnAdvancingToNextSeat($seat, $seatIndex);
    }

    private function finishTurnWithExtraTurn(): void
    {
        // turnSeatIndex intentionally unchanged -- the acting seat gets an
        // additional turn, per the appendix's "Play"/"Wild" sections.
        $this->syncOnlineState();
        $this->reloadMyHand();
    }

    private function finishTurnAdvancingToNextSeat(RoomSeat $seat, int $seatIndex): void
    {
        $this->turnSeatIndex = ($this->turnSeatIndex + 1) % count($this->seats);

        if ($this->hasGoneOut($seat)) {
            $this->endRound($seatIndex);

            return;
        }

        $this->syncOnlineState();
        $this->reloadMyHand();
    }

    private function hasGoneOut(RoomSeat $seat): bool
    {
        return ($seat->hand ?? []) === []
            && ($seat->table_face_up ?? []) === []
            && ($seat->table_face_down ?? []) === [];
    }

    private function trailingRunLength(int $value): int
    {
        $run = 0;

        for ($i = count($this->centerStack) - 1; $i >= 0; $i--) {
            if ($this->centerStack[$i] !== $value) {
                break;
            }

            $run++;
        }

        return $run;
    }

    /**
     * Scores every other seat's remaining cards (Ace=1, 2-9 face value,
     * Jack=11, Queen=12, King=13, Ten=25 wherever it remains -- see
     * docs/specs/clear-the-deck.md section 3) and adds it to their
     * cumulative total; the seat that went out scores 0 this round.
     */
    private function endRound(int $wentOutSeatIndex): void
    {
        $roomSeats = RoomSeat::where('room_id', $this->resolveRoom()->id)->get()->keyBy('seat_index');

        $roundScores = [];

        foreach ($roomSeats as $seatIndex => $roomSeat) {
            if ($seatIndex === $wentOutSeatIndex) {
                $roundScores[$seatIndex] = 0;

                continue;
            }

            $cards = [...($roomSeat->hand ?? []), ...($roomSeat->table_face_up ?? []), ...($roomSeat->table_face_down ?? [])];
            $roundScores[$seatIndex] = array_sum(array_map(self::cardPoints(...), $cards));
        }

        foreach ($roundScores as $seatIndex => $points) {
            $this->scores[$seatIndex] = ($this->scores[$seatIndex] ?? 0) + $points;
        }

        $this->roundsWonCounts[$wentOutSeatIndex] = ($this->roundsWonCounts[$wentOutSeatIndex] ?? 0) + 1;
        $this->roundScores = $roundScores;
        $this->wentOutSeatIndex = $wentOutSeatIndex;
        $this->lastMessage = $this->seatName($wentOutSeatIndex).' went out!';

        if ($this->currentRound >= $this->roundTarget) {
            $this->phase = ClearTheDeckPhase::GameEnd;
            $this->finishGame();
        } else {
            $this->phase = ClearTheDeckPhase::RoundEnd;
        }

        $this->syncOnlineState();
        $this->reloadMyHand();
    }

    /**
     * Ends the game right now, before $roundTarget rounds are reached --
     * host-only (docs/concerns.md), mirroring pages::farkle.play's own
     * endGameManually(). Reuses finishGame() as-is: it already just scores
     * from whatever $this->scores currently holds (every *completed*
     * round's points), so ending early simply stops counting further
     * rounds rather than needing a separate code path. Only meaningful
     * between rounds (mid-round Playing has no defined behavior for this
     * button today -- see the Blade's own @if ($this->canEndGameManually)
     * guard, which is intentionally phase-agnostic here since there's no
     * partial-round score to protect against).
     */
    public function endGameManually(): void
    {
        abort_unless($this->isHost, 403);

        if ($this->phase === ClearTheDeckPhase::GameEnd) {
            return;
        }

        $this->phase = ClearTheDeckPhase::GameEnd;
        $this->finishGame();
        $this->syncOnlineState();
    }

    private function finishGame(): void
    {
        $session = GameSession::findOrFail($this->gameSessionId);
        $session->update(['ended_at' => now()]);
        $this->resolveRoom()->update(['status' => RoomStatus::Finished]);

        $roomSeats = RoomSeat::where('room_id', $this->resolveRoom()->id)->get();

        $minScore = min($this->scores);
        $winningSeatIndexes = collect($this->scores)->filter(fn (int $s) => $s === $minScore)->keys()->all();
        $isTie = count($winningSeatIndexes) > 1;

        foreach ($roomSeats as $roomSeat) {
            $score = $this->scores[$roomSeat->seat_index] ?? 0;
            $isWinningScore = in_array($roomSeat->seat_index, $winningSeatIndexes, true);

            $result = match (true) {
                $isWinningScore && $isTie => GameResult::Draw,
                $isWinningScore => GameResult::Win,
                default => GameResult::Loss,
            };

            GameParticipant::create([
                'game_session_id' => $session->id,
                'user_id' => $roomSeat->user_id,
                'guest_name' => null,
                'score' => $score,
                'result' => $result,
                'stats' => ['rounds_won' => $this->roundsWonCounts[$roomSeat->seat_index] ?? 0],
            ]);
        }

        $this->winnerName = collect($winningSeatIndexes)->map(fn (int $i) => $this->seatName($i))->join(', ');
    }

    /**
     * Any seated player may advance -- a shared "we're ready to continue"
     * decision, not a turn, same reasoning as Best Match's own nextRound().
     * Rotates the dealer, re-deals a fresh 20 cards to every seat, and
     * resets the center stack.
     */
    public function nextRound(): void
    {
        abort_unless($this->mySeatIndex !== null, 403);
        abort_unless($this->phase === ClearTheDeckPhase::RoundEnd, 403);

        $seatCount = count($this->seats);

        $this->dealerSeatIndex = ($this->dealerSeatIndex + 1) % $seatCount;
        $this->turnSeatIndex = ($this->dealerSeatIndex + 1) % $seatCount;
        $this->currentRound++;

        $this->dealSeats(range(0, $seatCount - 1));

        $this->centerStack = [];
        $this->roundScores = [];
        $this->wentOutSeatIndex = null;
        $this->lastMessage = null;
        $this->phase = ClearTheDeckPhase::Playing;

        $this->syncOnlineState();
        $this->reloadMyHand();
    }

    /**
     * Deals a fresh 20-card hand (4 face-down, 4 face-up, 12-card hand) to
     * every given seat from a freshly shuffled virtual deck. Duplicated
     * (not shared) with pages::clear-the-deck.room's own identical initial
     * deal -- see that file's own docblock on dealSeats() for why.
     *
     * @param  list<int>  $seatIndexes
     */
    private function dealSeats(array $seatIndexes): void
    {
        $seatCount = count($seatIndexes);
        $totalCards = 20 * $seatCount;
        $fullSets = (int) ceil($totalCards / 13);

        $deck = collect();

        for ($i = 0; $i < $fullSets; $i++) {
            $deck->push(...range(1, 13));
        }

        $deck = $deck->shuffle()->take($totalCards)->values();

        foreach ($seatIndexes as $seatIndex) {
            RoomSeat::where('room_id', $this->resolveRoom()->id)->where('seat_index', $seatIndex)->update([
                'table_face_down' => $deck->splice(0, 4)->values()->all(),
                'table_face_up' => $deck->splice(0, 4)->values()->all(),
                'hand' => $deck->splice(0, 12)->values()->all(),
            ]);
        }
    }

    private function reloadMyHand(): void
    {
        if ($this->mySeatIndex === null) {
            return;
        }

        $seat = RoomSeat::where('room_id', $this->resolveRoom()->id)
            ->where('seat_index', $this->mySeatIndex)
            ->first();

        $this->myHand = $seat->hand ?? [];
    }

    private function seatName(int $seatIndex): ?string
    {
        return collect($this->seats)->firstWhere('seatIndex', $seatIndex)['name'] ?? null;
    }

    private static function cardPoints(int $value): int
    {
        return $value === 10 ? 25 : $value;
    }

    private static function cardLabel(int $value): string
    {
        return match ($value) {
            1 => 'Ace',
            10 => 'Ten',
            11 => 'Jack',
            12 => 'Queen',
            13 => 'King',
            default => (string) $value,
        };
    }

};
?>

<x-slot:title>Clear the Deck (Tens) — Low Stim Play</x-slot>
<x-slot:description>A shared center pile, private hands, and table cards hidden even from their own owner until played -- a turn-based shedding game for 3-10 players.</x-slot:description>
<x-slot:bodyClass>clear-the-deck-play</x-slot:bodyClass>
<x-slot:fullscreenToggle>1</x-slot:fullscreenToggle>
<x-slot:rulesTitle>Clear the Deck (Tens) Rules</x-slot:rulesTitle>
<x-slot:rules>
    <h3>How to Use This Page</h3>
    <ul>
        <li>Every player joins from their own device -- there's no shared/host screen, since hands and face-down cards are private.</li>
        <li>On your turn, select cards of the same value from your hand and/or your own face-up table cards, and choose how many of your own remaining face-down cards to flip and add in -- then play.</li>
        <li>Four or more matching cards clears the shared pile and gives you another turn. Playing higher than the pile's current top card makes you pick the whole pile up into your hand, and play passes to the next player. Otherwise, play also passes to the next player.</li>
        <li>Tens are wild -- playing one always clears the pile.</li>
    </ul>

    <h3>Full Rules</h3>
    <ul>
        <li>Each player starts a round with a 12-card hand, 4 face-up table cards, and 4 face-down table cards whose identity stays hidden -- even from that player -- until played.</li>
        <li>A face-down card can only be flipped once it's no longer covered by one of your own face-up cards.</li>
        <li>The round ends the instant any player empties their hand and both table piles; everyone else scores the value of every card they have left (Ace=1, 2-9 face value, Jack=11, Queen=12, King=13, Ten=25) and the player who went out scores 0.</li>
        <li>After every configured round is played, the lowest total score wins.</li>
        <li>The host can press End Game any time between rounds to stop early and score whoever's lowest so far as the winner.</li>
    </ul>
</x-slot:rules>
<div>
    @if ($room !== null)
        <livewire:room-new-game-notice :room-code="$room" :current-game="GameType::ClearTheDeck" :my-game-session-id="$gameSessionId" wire:key="room-new-game-notice" />
    @endif

    @if ($started)
        @teleport('#game-actions')
            <div style="display: contents;">
                {{--
                    For casting to a TV or screen-sharing (docs/concerns.md,
                    the request that added Clear the Deck's spectator view)
                    -- mirrors pages::yahtzee.play's own "Watch only"/"Player
                    view" toggle exactly. Only shown to a seated player;
                    someone with no seat at all is already always spectating,
                    nothing to toggle.
                --}}
                @if ($mySeatIndex !== null)
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

                @if ($phase === ClearTheDeckPhase::GameEnd)
                    <a href="{{ route('room.show', ['code' => $room]) }}"><button type="button">Back to room</button></a>
                @endif
            </div>
        @endteleport
    @endif

    @if ($room === null)
        <div class="setup" id="setup">
            <div class="heading">Clear the Deck (Tens)</div>

            @if ($this->hostedRooms->isNotEmpty())
                <div class="flex flex-col items-center gap-2 w-full">
                    <p class="status-line">Play in a room you host:</p>
                    <div class="flex flex-wrap justify-center gap-2">
                        @foreach ($this->hostedRooms as $hostedRoom)
                            <a href="{{ route('clear-the-deck.room', ['code' => $hostedRoom->code]) }}" wire:key="hosted-room-{{ $hostedRoom->id }}">
                                <button type="button" id="hosted-room-{{ $hostedRoom->code }}">{{ $hostedRoom->code }}</button>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            <p class="status-line">Clear the Deck (Tens) is online-only -- open <strong>Rooms</strong> in the top bar to create or join a room, then start Clear the Deck (Tens) from there.</p>
        </div>
    @else
        @php
            $mainSize = 'clamp(58px, 9vw, 68px)';
            $oppSize = 'clamp(26px, 5vw, 40px)';

            // Spectator/TV-cast view (docs/concerns.md): every seat's
            // tableau needs to fit one screen with no scrolling, for any
            // seat count this game allows (3-10, see docs/specs/
            // clear-the-deck.md) and any display shape -- a wide-but-short
            // TV/cast display and a narrower-but-taller tablet both have to
            // work, and AirPlay/screen-mirroring can hand the page a
            // noticeably different aspect ratio than the device it started
            // on. Capped at 5 columns (so 3-10 seats is always 1-2 rows,
            // never more) and sized from *both* the viewport width (fitting
            // $spectatorCols tableaus across) and height (fitting
            // $spectatorRows rows down, leaving headroom for the pile row
            // above and the header/status line around it) via CSS min() --
            // whichever axis is tighter wins, so this shrinks correctly
            // regardless of which dimension a given display is short on.
            // Constants tuned empirically against real screenshots at 3, 6,
            // and 10 seats across desktop/tablet/TV viewports -- see
            // docs/DEVLOG.md's Clear the Deck section.
            $spectatorCount = count($seats);
            $spectatorCols = min($spectatorCount, 5);
            $spectatorRows = (int) ceil($spectatorCount / $spectatorCols);
            $spectatorVw = round(84 / ($spectatorCols * 4.6), 2);
            $spectatorVh = round(55 / ($spectatorRows * 1.75), 2);
            $spectatorSize = "clamp(30px, min({$spectatorVw}vw, {$spectatorVh}vh), 120px)";
        @endphp
        <div id="clear-the-deck-game" class="flex flex-col items-center w-full" style="gap: inherit;">
            @unless ($phase === ClearTheDeckPhase::GameEnd)
                @php
                    $topValue = $centerStack === [] ? null : end($centerStack);
                    $runLength = 0;
                    for ($i = count($centerStack) - 1; $i >= 0; $i--) {
                        if ($centerStack[$i] !== $topValue) {
                            break;
                        }
                        $runLength++;
                    }
                    $fanDepth = min(3, $runLength);
                @endphp

                @if ($this->isSpectator)
                    {{--
                        docs/concerns.md: the pile renders *above* the seat
                        grid here, not below it (unlike the playing view's
                        own order just below) -- a spectator has no hand or
                        "your columns" section of their own to read first,
                        so the shared table state is the thing that should
                        be visible without scrolling past every seat to find
                        it. ctd-pile-row-spectator trims the generous margin
                        the playing view's own copy uses, since this is
                        competing with the seat grid below for the same
                        fixed vertical budget.
                    --}}
                    <div class="ctd-pile-row ctd-pile-row-spectator" style="--ctd-card-w: {{ $mainSize }};">
                        <div class="flex flex-col items-center gap-2">
                            <p class="ctd-section-label">On the table<br>({{ count($centerStack) }} card{{ count($centerStack) === 1 ? '' : 's' }})</p>
                            @if ($centerStack === [])
                                <p class="status-line">Empty &mdash; any value can be played.</p>
                            @else
                                <div class="ctd-pile-wrap">
                                    @for ($i = 0; $i < $fanDepth; $i++)
                                        <x-clear-the-deck-card
                                            :value="$topValue"
                                            :size="$mainSize"
                                            class="ctd-card-offset-{{ $i }}"
                                            :sub="$i === $fanDepth - 1 && $runLength > 1 ? '(x'.$runLength.')' : null" />
                                    @endfor
                                </div>
                            @endif
                        </div>
                    </div>

                    {{--
                        docs/concerns.md: a TV/screen-share-friendly view --
                        every seat's tableau (not just "opponents", since a
                        watch-only seated player's own columns are part of
                        this too), and the current turn's seat visually
                        outlined so it reads at a glance from across a room.
                        $spectatorCols/$spectatorSize above size this so the
                        whole grid -- 3 to 10 seats -- fits one screen with
                        no scrolling on both a wide TV and a taller tablet.
                    --}}
                    <div class="ctd-spectator-grid w-full" style="grid-template-columns: repeat({{ $spectatorCols }}, auto);">
                        @foreach ($seats as $seat)
                            @php
                                $table = $seatTables[$seat['seatIndex']] ?? ['handCount' => 0, 'tableFaceUp' => [], 'tableFaceDownCount' => 0];
                                $isCurrentTurn = $seat['seatIndex'] === $turnSeatIndex;
                            @endphp
                            <div wire:key="spectator-seat-{{ $seat['seatIndex'] }}" class="flex flex-col items-center gap-1 ctd-spectator-seat {{ $isCurrentTurn ? 'ctd-spectator-seat-active' : '' }}">
                                <span class="ctd-spectator-name {{ $isCurrentTurn ? 'ctd-opponent-name-active' : '' }}">{{ $isCurrentTurn ? '▸ ' : '' }}{{ $seat['name'] }}</span>
                                <div class="ctd-tableau" style="--ctd-card-w: {{ $spectatorSize }};">
                                    @foreach ($this->columnsForSeat($seat['seatIndex']) as $col)
                                        <div class="ctd-column" wire:key="spectator-{{ $seat['seatIndex'] }}-col-{{ $col['type'] }}-{{ $col['index'] }}">
                                            @if ($col['hasFaceDown'])
                                                <x-clear-the-deck-card face-down :size="$spectatorSize" />
                                            @endif
                                            @if ($col['value'] !== null)
                                                <x-clear-the-deck-card :value="$col['value']" :size="$spectatorSize" :class="$col['hasFaceDown'] ? '' : 'ctd-column-no-peek'" />
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                                <span class="ctd-spectator-hand">Hand: {{ $table['handCount'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    @php $opponents = collect($seats)->reject(fn (array $seat) => $seat['seatIndex'] === $mySeatIndex)->values() @endphp
                    <div class="ctd-opponents-row w-full">
                        @foreach ($opponents as $seat)
                            @php $table = $seatTables[$seat['seatIndex']] ?? ['handCount' => 0, 'tableFaceUp' => [], 'tableFaceDownCount' => 0] @endphp
                            <div wire:key="seat-{{ $seat['seatIndex'] }}" class="flex flex-col items-center gap-1">
                                <span class="ctd-opponent-name">{{ $seat['name'] }}</span>
                                <div class="ctd-tableau" style="--ctd-card-w: {{ $oppSize }};">
                                    @foreach ($this->columnsForSeat($seat['seatIndex']) as $col)
                                        <div class="ctd-column" wire:key="opp-{{ $seat['seatIndex'] }}-col-{{ $col['type'] }}-{{ $col['index'] }}">
                                            @if ($col['hasFaceDown'])
                                                <x-clear-the-deck-card face-down :size="$oppSize" />
                                            @endif
                                            @if ($col['value'] !== null)
                                                <x-clear-the-deck-card :value="$col['value']" :size="$oppSize" :class="$col['hasFaceDown'] ? '' : 'ctd-column-no-peek'" />
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                                <span class="text-xs text-text/65">Hand: {{ $table['handCount'] }}</span>
                            </div>
                        @endforeach
                    </div>

                    <div class="ctd-pile-row" style="--ctd-card-w: {{ $mainSize }};">
                        <div class="flex flex-col items-center gap-2">
                            <p class="ctd-section-label">On the table<br>({{ count($centerStack) }} card{{ count($centerStack) === 1 ? '' : 's' }})</p>
                            @if ($centerStack === [])
                                <p class="status-line">Empty &mdash; any value can be played.</p>
                            @else
                                <div class="ctd-pile-wrap">
                                    @for ($i = 0; $i < $fanDepth; $i++)
                                        <x-clear-the-deck-card
                                            :value="$topValue"
                                            :size="$mainSize"
                                            class="ctd-card-offset-{{ $i }}"
                                            :sub="$i === $fanDepth - 1 && $runLength > 1 ? '(x'.$runLength.')' : null" />
                                    @endfor
                                </div>
                            @endif
                        </div>
                        @if ($this->isMyTurn)
                            <button type="button" id="play-cards" wire:click="playCards">{{ $pendingFlipValue !== null ? 'Confirm' : 'Submit' }}</button>
                        @endif
                    </div>
                @endif

                @if ($mySeatIndex !== null && ! $this->isSpectator)
                    <div class="flex flex-col items-center gap-2">
                        <p class="ctd-section-label">Your columns</p>
                        <div class="ctd-tableau" style="--ctd-card-w: {{ $mainSize }};">
                            @foreach ($this->myColumns as $col)
                                @php
                                    $isSelectedFaceDown = $col['type'] === 'faceDown' && in_array($col['index'], $selectedFaceDownColumns, true);
                                    $faceDownInteractive = $this->isMyTurn && ($pendingFlipValue === null || $isSelectedFaceDown);
                                    $faceUpInteractive = $this->isMyTurn && ($pendingFlipValue === null || $col['value'] === $pendingFlipValue);
                                @endphp
                                <div class="ctd-column" wire:key="my-col-{{ $col['type'] }}-{{ $col['index'] }}">
                                    @if ($col['hasFaceDown'])
                                        @if ($col['type'] === 'faceDown' && $faceDownInteractive)
                                            <x-clear-the-deck-card face-down clickable
                                                :size="$mainSize"
                                                :selected="$isSelectedFaceDown"
                                                wire:click="toggleFaceDownSelection({{ $col['index'] }})" />
                                        @else
                                            <x-clear-the-deck-card face-down :size="$mainSize"
                                                :disabled="$col['type'] === 'faceDown' && $pendingFlipValue !== null" />
                                        @endif
                                    @endif
                                    @if ($col['value'] !== null)
                                        @if ($faceUpInteractive)
                                            <x-clear-the-deck-card :value="$col['value']" clickable
                                                :size="$mainSize"
                                                :selected="in_array($col['index'], $selectedFaceUpIndices, true)"
                                                :class="$col['hasFaceDown'] ? '' : 'ctd-column-no-peek'"
                                                wire:click="toggleFaceUpCard({{ $col['index'] }})" />
                                        @else
                                            <x-clear-the-deck-card :value="$col['value']" :size="$mainSize"
                                                :class="$col['hasFaceDown'] ? '' : 'ctd-column-no-peek'"
                                                :disabled="$this->isMyTurn && $pendingFlipValue !== null" />
                                        @endif
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endunless

            @if ($phase === ClearTheDeckPhase::Playing)
                @if ($this->isMyTurn && ! $this->isSpectator)
                    @if ($pendingFlipValue !== null)
                        <p class="status-line">You flipped a {{ self::cardLabel($pendingFlipValue) }}! Tap any more {{ self::cardLabel($pendingFlipValue) }}s to add them &mdash; optional &mdash; then Confirm.</p>
                    @endif

                    @if ($playError)
                        <p class="status-line" style="color: var(--warn);">{{ $playError }}</p>
                    @endif

                    <div class="flex flex-col items-center gap-2 w-full">
                        <p class="ctd-section-label">Your hand</p>
                        <div class="ctd-hand-grid" style="--ctd-card-w: {{ $mainSize }};">
                            @foreach ($myHand as $index => $value)
                                @php $handCardInteractive = $pendingFlipValue === null || $value === $pendingFlipValue @endphp
                                @if ($handCardInteractive)
                                    <x-clear-the-deck-card :value="$value" clickable
                                        :size="$mainSize"
                                        :selected="in_array($index, $selectedHandIndices, true)"
                                        wire:key="hand-{{ $index }}"
                                        wire:click="toggleHandCard({{ $index }})" />
                                @else
                                    <x-clear-the-deck-card :value="$value" disabled
                                        :size="$mainSize"
                                        wire:key="hand-{{ $index }}" />
                                @endif
                            @endforeach
                        </div>
                    </div>
                @elseif ($this->isSpectator)
                    <p class="status-line">Watching -- {{ $this->seatName($turnSeatIndex) }}'s turn.</p>
                @else
                    <p class="status-line">Waiting for {{ $this->seatName($turnSeatIndex) }}...</p>
                @endif
            @endif

            @if ($phase === ClearTheDeckPhase::RoundEnd)
                <div class="panel">
                    <div class="panel-title">{{ $this->seatName($wentOutSeatIndex) }} went out!</div>
                    <div class="flex flex-wrap justify-center gap-3 w-full">
                        @foreach ($seats as $seat)
                            <div wire:key="round-score-{{ $seat['seatIndex'] }}" class="flex flex-col items-center gap-1 bg-surface border-2 border-surface-border rounded-lg px-4 py-2">
                                <span class="text-sm text-text/80">{{ $seat['name'] }}</span>
                                <span class="text-sm text-text">+{{ $roundScores[$seat['seatIndex']] ?? 0 }} this round</span>
                                <span class="text-xs text-text/65">{{ $scores[$seat['seatIndex']] ?? 0 }} total</span>
                            </div>
                        @endforeach
                    </div>
                    @if ($mySeatIndex !== null)
                        <div class="controls">
                            <button type="button" wire:click="nextRound">Next Round</button>
                        </div>
                    @endif
                </div>
            @endif

            @if ($phase === ClearTheDeckPhase::GameEnd)
                <div class="panel">
                    <div class="panel-title">Final Scores</div>
                    <div class="panel-title">{{ $winnerName }} wins!</div>
                    <div class="flex flex-wrap justify-center gap-3 w-full">
                        @foreach ($seats as $seat)
                            <div wire:key="final-score-{{ $seat['seatIndex'] }}" class="flex flex-col items-center gap-1 bg-surface border-2 border-surface-border rounded-lg px-4 py-2">
                                <span class="text-sm text-text/80">{{ $seat['name'] }}</span>
                                <span class="text-lg font-bold text-text">{{ $scores[$seat['seatIndex']] ?? 0 }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
