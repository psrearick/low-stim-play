<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Enums\YahtzeeCategory;
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

    public function mount(string $code): void
    {
        $this->room = Room::notExpired()->where('code', strtoupper($code))->firstOrFail();

        // Arriving here directly (a shared room URL) rather than through the
        // /yahtzee setup screen's own "Join" form -- that form already
        // provisions a guest before redirecting here, but this route has no
        // auth middleware of its own (see routes/web.php), so a first-time
        // direct visitor still needs one.
        if (Auth::guest()) {
            Auth::login(User::createGuest());

            // Guarded rather than unconditional -- Livewire::test() drives
            // this component directly, without the full HTTP kernel/session
            // middleware a real request goes through, so there's no session
            // store attached to request() there.
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
            $this->room->markPendingGame(GameType::Yahtzee);
        }

        // The game may already be running by the time anyone actually
        // lands here -- a reconnecting seated player whose device was
        // disconnected at the exact moment the host clicked "Start" would
        // otherwise never receive RoomGameStarting (onRoomStarted() below
        // only reacts to that broadcast arriving *after* mount, which
        // never happens for them) and would be stuck looking at this
        // now-stale lobby forever, even though the game is running fine
        // for everyone else. This also doubles as the only real entry
        // point into the spectator/host view (see pages::yahtzee.play):
        // anyone -- seated or not -- who visits this room URL once the
        // game has started is sent straight into it.
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
            // Local (non-broadcast) event dispatched by the nested GameLobby
            // when its host clicks "Start game" -- only the host's own
            // request reaches this, since GameLobby::startGame() aborts for
            // anyone else. Handling it here (rather than in GameLobby
            // itself) keeps GameLobby game-agnostic; Yahtzee owns deciding
            // what "starting" means for Yahtzee (seat assignment + an
            // initial shared scorecard state).
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

            $emptyScores = array_fill_keys(
                array_map(fn (YahtzeeCategory $category) => $category->value, YahtzeeCategory::all()),
                null,
            );

            $state = [
                'seats' => array_map(
                    fn (array $player, int $index) => [
                        'seatIndex' => $index,
                        'name' => $player['name'],
                        'scores' => $emptyScores,
                        'yahtzeeBonusCount' => 0,
                    ],
                    $seats,
                    array_keys($seats),
                ),
                'currentSeat' => 0,
                'dice' => [],
                'kept' => [],
                'rollsLeft' => 3,
                'gameOver' => false,
            ];

            $session = GameSession::create([
                'type' => GameType::Yahtzee,
                'mode' => GameMode::Online,
                'settings' => ['player_count' => count($seats)],
                'state' => $state,
                'started_at' => now(),
            ]);

            // Cleared first, not just created -- a room can be replayed
            // (game-lobby's startGame() resets game_session_id to null on
            // every "Start Game" click, not just the first) or switched to
            // a different game entirely and back (see GameType::roomRoute()
            // and switchGame() on <livewire:game-lobby>), and this branch
            // runs again either way. Without this, a second Yahtzee game in
            // the same room left the *previous* game's seat rows in place
            // and just added a second set on top, so every seat-scoped
            // query (mySeatIndex, seatUserIds in endGame(), ...) then saw
            // duplicate rows for the same room/seat_index.
            RoomSeat::where('room_id', $this->room->id)->delete();

            foreach ($seats as $index => $player) {
                RoomSeat::create([
                    'room_id' => $this->room->id,
                    'seat_index' => $index,
                    'user_id' => $player['id'],
                ]);
            }

            $this->room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);

            // Broadcast only now that game_session_id/seats are actually
            // set -- see pages::boggle.room::onRoomStarting() for why this
            // ordering (write first, broadcast second) matters: a guest
            // reacting to the broadcast needs the DB write to already be
            // visible when it checks.
            RoomGameStarting::dispatch($this->room);
        }

        $this->goToGame();
    }

    public function onRoomStarted(): void
    {
        $this->room->refresh();
        $this->redirectIfAlreadyStarted();
    }

    private function goToGame(): void
    {
        $this->redirect(route('yahtzee.play', ['room' => $this->room->code]));
    }
};
?>

<x-slot:title>Yacht Room — Low Stim Play</x-slot>

<div class="flex flex-col items-center" style="gap: inherit;">
    <div class="heading">Yacht Room</div>

    {{--
        No pre-start setup here, unlike Boggle's room page (board
        size/time actually shape the generated board) -- Yahtzee's seat
        count is simply however many players are present and ready when
        the host starts, nothing to configure ahead of that.
    --}}
    <livewire:game-lobby :room="$room" :current-game="GameType::Yahtzee" />
</div>
