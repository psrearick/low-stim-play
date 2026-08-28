<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Events\RoomGameStarting;
use App\Events\RoomGameSwitched;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use App\Services\Farkle\DiceRoller;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public Room $room;

    /**
     * Host-configured before starting -- mirrors pages::boggle.room's own
     * $size/$minutes exactly, just for Farkle's own settings instead. See
     * pages::farkle.play's own setup screen for the pass-and-play
     * equivalent of these same two options.
     */
    public int $targetScore = self::NORMAL_DEFAULT_TARGET_SCORE;

    public bool $withFinalRound = true;

    public bool $spicyEnabled = false;

    public bool $highStakesEnabled = false;

    /**
     * Gates <livewire:game-lobby> -- false the entire time the host is
     * still on this page's own config fields above, so nobody else gets
     * notified/can join until the host explicitly opens the lobby (see
     * openLobby() below). Re-derived from Room::pendingGameType() on every
     * mount() rather than stored anywhere new, so a reload or a return
     * visit lands back in the right phase automatically.
     */
    public bool $lobbyOpened = false;

    /**
     * @var list<int>
     */
    public const TARGET_SCORE_OPTIONS = [5000, 7500, 10000, 15000];

    /**
     * @var list<int>
     */
    public const SPICY_TARGET_SCORE_OPTIONS = [15000, 25000, 35000, 50000];

    public const NORMAL_DEFAULT_TARGET_SCORE = 10000;

    public const SPICY_DEFAULT_TARGET_SCORE = 25000;

    /**
     * See pages::farkle.play's identical setSpicyEnabled() -- resets
     * $targetScore to whichever mode's own default rather than leaving a
     * previously-picked value in place (docs/specs/farkle.md §6.6).
     */
    public function setSpicyEnabled(bool $enabled): void
    {
        $this->spicyEnabled = $enabled;
        $this->targetScore = $enabled ? self::SPICY_DEFAULT_TARGET_SCORE : self::NORMAL_DEFAULT_TARGET_SCORE;
    }

    public function mount(string $code): void
    {
        $this->room = Room::notExpired()->where('code', strtoupper($code))->firstOrFail();

        // Arriving here directly (a shared room URL) rather than through
        // the /farkle setup screen's own "Join" form -- see
        // pages::yahtzee.room's identical mount() for why this matters:
        // that form already provisions a guest before redirecting here,
        // but this route has no auth middleware of its own.
        if (Auth::guest()) {
            Auth::login(User::createGuest(request()->cookie('guest_name')));

            if (request()->hasSession()) {
                request()->session()->regenerate();
            }
        }

        $this->room->addMember(Auth::user());

        // See pages::yahtzee.room's identical check for why this matters:
        // a reconnecting seated player whose device dropped at exactly the
        // wrong moment would otherwise never receive RoomGameStarting and
        // could get stuck looking at a stale lobby.
        $this->redirectIfAlreadyStarted();

        // Reflects whatever's already true rather than assuming this is a
        // fresh visit -- the host reloading after already clicking Open
        // Lobby (or a member arriving after that already happened) both
        // need to land past the config screen, not back at square one.
        $this->lobbyOpened = $this->room->pendingGameType() === GameType::Farkle;
    }

    /**
     * The explicit moment this room's config screen turns into the real
     * waiting room -- everyone else was previously notified/able to join
     * the instant the host so much as loaded this page, before touching a
     * single setting (docs/concerns.md). markPendingGame()/RoomGameSwitched
     * moved here from both this page's own mount() and pages::room.show's
     * startGame(), so nothing fires until the host deliberately confirms.
     */
    public function openLobby(): void
    {
        abort_unless($this->isHost, 403);

        $this->room->markPendingGame(GameType::Farkle);
        RoomGameSwitched::dispatch($this->room, GameType::Farkle);
        $this->lobbyOpened = true;
    }

    private function redirectIfAlreadyStarted(): void
    {
        if ($this->room->status === RoomStatus::Active && $this->room->game_session_id !== null) {
            $this->goToGame();
        }
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return [
            "echo-presence:room.{$this->room->code},RoomGameStarting" => 'onRoomStarted',
            "echo-presence:room.{$this->room->code},RoomGameSwitched" => 'onGameSwitchedElsewhere',
            'room-game-starting' => 'onRoomStarting',
        ];
    }

    /**
     * Recomputes $lobbyOpened for whoever's already sitting on this exact
     * page -- covers both the host opening this same room in a second tab
     * after already clicking Open Lobby elsewhere, and a non-host who
     * loaded this page before the host had (see getSwitchedElsewhereProperty()
     * below for the case where the broadcast is for a *different* game).
     */
    public function onGameSwitchedElsewhere(): void
    {
        $this->room->refresh();
        $this->lobbyOpened = $this->room->pendingGameType() === GameType::Farkle;
    }

    /**
     * Mirrors <livewire:game-lobby>'s own getSwitchedGameTypeProperty() --
     * the equivalent case for a visitor still on *this* page's own
     * pre-lobby-open screens (config or the non-host waiting message),
     * which game-lobby itself can't cover since it isn't rendered yet.
     */
    public function getSwitchedElsewhereProperty(): ?GameType
    {
        $pending = $this->room->pendingGameType();

        return $pending !== null && $pending !== GameType::Farkle ? $pending : null;
    }

    /**
     * @param  array<int, array{id: int, name: string, is_host: bool, ready: bool}>  $players
     */
    public function onRoomStarting(array $players): void
    {
        $this->room->refresh();

        if ($this->room->game_session_id === null) {
            $seats = array_values($players);

            $state = [
                'seats' => array_map(
                    fn (array $player, int $index) => [
                        'seatIndex' => $index,
                        'name' => $player['name'],
                        'bankedScore' => 0,
                        'hasQualified' => false,
                        'farkleCount' => 0,
                        'hotDiceCount' => 0,
                        'bestTurnScore' => 0,
                    ],
                    $seats,
                    array_keys($seats),
                ),
                'currentSeat' => 0,
                'dice' => [],
                'selected' => [],
                'dieSpicy' => [],
                'activeDieSlots' => range(1, DiceRoller::DICE_COUNT),
                'lastBankInheritance' => null,
                'bankedDiceThisTurn' => [],
                'turnScore' => 0,
                'heldDiceCount' => 0,
                'targetScore' => $this->targetScore,
                'withFinalRound' => $this->withFinalRound,
                'spicyEnabled' => $this->spicyEnabled,
                'highStakesEnabled' => $this->highStakesEnabled,
                'finalRoundActive' => false,
                'finalRoundRemaining' => null,
                'gameOver' => false,
                'message' => null,
                'messageType' => null,
            ];

            $session = GameSession::create([
                'type' => GameType::Farkle,
                'mode' => GameMode::Online,
                'settings' => [
                    'player_count' => count($seats),
                    'target_score' => $this->targetScore,
                    'with_final_round' => $this->withFinalRound,
                    'spicy_enabled' => $this->spicyEnabled,
                    'high_stakes_enabled' => $this->highStakesEnabled,
                ],
                'state' => $state,
                'started_at' => now(),
            ]);

            // Cleared first, not just created -- see pages::yahtzee.room's
            // identical line for why: a replayed or switched-back-to room
            // reuses the same code, and without this a second Farkle game
            // here would pile duplicate seat rows on top of the first.
            RoomSeat::where('room_id', $this->room->id)->delete();

            foreach ($seats as $index => $player) {
                RoomSeat::create([
                    'room_id' => $this->room->id,
                    'seat_index' => $index,
                    'user_id' => $player['id'],
                ]);
            }

            $this->room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);

            RoomGameStarting::dispatch($this->room);
        }

        $this->goToGame();
    }

    public function onRoomStarted(): void
    {
        $this->room->refresh();
        $this->redirectIfAlreadyStarted();
    }

    public function getIsHostProperty(): bool
    {
        return Auth::id() === $this->room->host_user_id;
    }

    private function goToGame(): void
    {
        $this->redirect(route('farkle.play', ['room' => $this->room->code]));
    }
};
?>

<x-slot:title>10,000 Room — Low Stim Play</x-slot>

<div class="flex flex-col items-center" style="gap: inherit;">
    <div class="heading">10,000 Room</div>

    @if ($this->switchedElsewhere)
        <div class="setup">
            <p class="status-line">The host switched to {{ $this->switchedElsewhere->label() }}.</p>
            <a href="{{ route($this->switchedElsewhere->roomRoute(), ['code' => $room->code]) }}">
                <button type="button">Join</button>
            </a>
        </div>
    @elseif ($lobbyOpened)
        <livewire:game-lobby :room="$room" :current-game="GameType::Farkle" />
    @elseif ($this->isHost)
        <div class="setup">
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

            <button type="button" id="open-lobby-btn" wire:click="openLobby">Open Lobby</button>
        </div>
    @else
        <div class="setup">
            <p class="status-line">Waiting for the host to finish setting up&hellip;</p>
        </div>
    @endif
</div>
