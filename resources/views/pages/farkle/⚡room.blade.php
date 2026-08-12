<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Events\RoomGameStarting;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
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
    public int $targetScore = 10000;

    public bool $withFinalRound = true;

    /**
     * @var list<int>
     */
    public const TARGET_SCORE_OPTIONS = [5000, 7500, 10000, 15000];

    public function mount(string $code): void
    {
        $this->room = Room::notExpired()->where('code', strtoupper($code))->firstOrFail();

        // Arriving here directly (a shared room URL) rather than through
        // the /farkle setup screen's own "Join" form -- see
        // pages::yahtzee.room's identical mount() for why this matters:
        // that form already provisions a guest before redirecting here,
        // but this route has no auth middleware of its own.
        if (Auth::guest()) {
            Auth::login(User::createGuest());

            if (request()->hasSession()) {
                request()->session()->regenerate();
            }
        }

        $this->room->addMember(Auth::user());

        // Lets a member who loads the room hub after the host already
        // landed here (missing the real-time RoomGameSwitched broadcast
        // entirely) still discover and join this lobby -- see Room::
        // markPendingGame()'s own docblock.
        if (Auth::id() === $this->room->host_user_id && $this->room->game_session_id === null) {
            $this->room->markPendingGame(GameType::Farkle);
        }

        // See pages::yahtzee.room's identical check for why this matters:
        // a reconnecting seated player whose device dropped at exactly the
        // wrong moment would otherwise never receive RoomGameStarting and
        // could get stuck looking at a stale lobby.
        $this->redirectIfAlreadyStarted();
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
            'room-game-starting' => 'onRoomStarting',
        ];
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
                'bankedDiceThisTurn' => [],
                'turnScore' => 0,
                'heldDiceCount' => 0,
                'targetScore' => $this->targetScore,
                'withFinalRound' => $this->withFinalRound,
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

    @if ($this->isHost)
        <div class="setup">
            <div class="setup-row">
                <div class="label">Target Score</div>
                <div class="option-group">
                    @foreach (self::TARGET_SCORE_OPTIONS as $option)
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
        </div>
    @endif

    <livewire:game-lobby :room="$room" :current-game="GameType::Farkle" />
</div>
