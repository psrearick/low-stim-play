<?php

use App\Enums\AppleMatchPhase;
use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Events\RoomGameStarting;
use App\Events\RoomGameSwitched;
use App\Models\AppleMatchDeck;
use App\Models\AppleMatchPrompt;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public const int MIN_PLAYERS = 3;

    public const int MIN_TARGET = 3;

    public const int MAX_TARGET = 10;

    public const int DEFAULT_TARGET = 4;

    public const int HAND_SIZE = 7;

    public Room $room;

    public int $targetScore = self::DEFAULT_TARGET;

    /**
     * Set only on the host's own connection when onRoomStarting() below
     * declines to actually start -- 'room-game-starting' is a local,
     * non-broadcast dispatch, so this never reaches anyone else, same
     * reasoning as $assigningTeams on pages::family-feud.room.
     */
    public ?string $startError = null;

    /**
     * Gates <livewire:game-lobby> -- false the entire time the host is
     * still on this page's own config fields above, so nobody else gets
     * notified/can join until the host explicitly opens the lobby (see
     * openLobby() below). Re-derived from Room::pendingGameType() on every
     * mount() rather than stored anywhere new, so a reload or a return
     * visit lands back in the right phase automatically.
     */
    public bool $lobbyOpened = false;

    public function mount(string $code): void
    {
        $this->room = Room::notExpired()->where('code', strtoupper($code))->firstOrFail();

        // Arriving here directly (a shared room URL), rather than through a
        // local setup screen -- see docs/specs/apple-match.md section 1:
        // this game has no pass-and-play setup screen at all, so a
        // first-time visitor always needs a guest account provisioned here,
        // same reasoning as every other {game}.room page.
        if (Auth::guest()) {
            Auth::login(User::createGuest(request()->cookie('guest_name')));

            // Guarded rather than unconditional -- Livewire::test() drives
            // this component directly, without the full HTTP kernel/session
            // middleware a real request goes through, so there's no session
            // store attached to request() there.
            if (request()->hasSession()) {
                request()->session()->regenerate();
            }
        }

        $this->room->addMember(Auth::user());

        $this->redirectIfAlreadyStarted();

        // Reflects whatever's already true rather than assuming this is a
        // fresh visit -- the host reloading after already clicking Open
        // Lobby (or a member arriving after that already happened) both
        // need to land past the config screen, not back at square one.
        $this->lobbyOpened = $this->room->pendingGameType() === GameType::AppleMatch;
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

        $this->room->markPendingGame(GameType::AppleMatch);
        RoomGameSwitched::dispatch($this->room, GameType::AppleMatch);
        $this->lobbyOpened = true;
    }

    private function redirectIfAlreadyStarted(): void
    {
        if ($this->room->status === RoomStatus::Active && $this->room->game_session_id !== null) {
            $this->goToGame();
        }
    }

    public function setTargetScore(int $target): void
    {
        $this->targetScore = max(self::MIN_TARGET, min(self::MAX_TARGET, $target));
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return [
            "echo-presence:room.{$this->room->code},RoomGameStarting" => 'onRoomStarted',
            "echo-presence:room.{$this->room->code},RoomGameSwitched" => 'onGameSwitchedElsewhere',
            // Local (non-broadcast) event dispatched by the nested GameLobby
            // when its host clicks "Start game" -- only the host's own
            // request reaches this, since GameLobby::startGame() aborts for
            // anyone else.
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
        $this->lobbyOpened = $this->room->pendingGameType() === GameType::AppleMatch;
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

        return $pending !== null && $pending !== GameType::AppleMatch ? $pending : null;
    }

    /**
     * Deals starting hands and creates the shared round state. Unlike
     * pages::family-feud.room's own empty-question-bank guard (a
     * misconfigured-deployment edge case that's effectively never hit in
     * real use), running into fewer than MIN_PLAYERS players here is a
     * completely ordinary occurrence -- so this sets $startError, visible
     * to the host on this same request/response, rather than silently
     * doing nothing.
     *
     * @param  array<int, array{id: int, name: string, is_host: bool, ready: bool}>  $players
     */
    public function onRoomStarting(array $players): void
    {
        $this->room->refresh();

        if ($this->room->game_session_id !== null) {
            $this->goToGame();

            return;
        }

        $seats = array_values($players);

        if (count($seats) < self::MIN_PLAYERS) {
            $this->startError = 'Best Match needs at least '.self::MIN_PLAYERS." players to start \u{2014} ".count($seats).' here right now.';

            return;
        }

        $deck = AppleMatchDeck::whereNull('owner_user_id')->where('source', 'system')->first();

        if ($deck === null || $deck->prompts()->doesntExist() || $deck->cards()->doesntExist()) {
            $this->startError = 'The Best Match card deck hasn\'t been seeded yet -- ask whoever runs this deployment to run its database seeder.';

            return;
        }

        $this->startError = null;

        $promptId = $deck->prompts()->inRandomOrder()->value('id');
        $cardIds = $deck->cards()->inRandomOrder()->limit(count($seats) * self::HAND_SIZE)->pluck('id')->all();

        // Cleared first, not just created -- a room can be replayed or
        // switched to a different game and back (see GameType::roomRoute()),
        // and this branch runs again either way. Same reasoning as every
        // other game's own onRoomStarting().
        RoomSeat::where('room_id', $this->room->id)->delete();

        foreach ($seats as $index => $player) {
            RoomSeat::create([
                'room_id' => $this->room->id,
                'seat_index' => $index,
                'user_id' => $player['id'],
                'hand' => array_splice($cardIds, 0, self::HAND_SIZE),
            ]);
        }

        $state = [
            'deckId' => $deck->id,
            'targetScore' => $this->targetScore,
            'seats' => array_map(
                fn (array $player, int $index) => ['seatIndex' => $index, 'name' => $player['name']],
                $seats,
                array_keys($seats),
            ),
            'scores' => array_fill(0, count($seats), 0),
            'judgedCounts' => array_fill(0, count($seats), 0),
            'judgeSeatIndex' => 0,
            'round' => 1,
            'phase' => AppleMatchPhase::Submitting->value,
            'usedPromptIds' => [$promptId],
            'currentPromptId' => $promptId,
            'currentPromptText' => AppleMatchPrompt::find($promptId)->text,
            'submittedSeats' => [],
            'anonymizedSubmissions' => [],
            'winningText' => null,
            'winningSeatName' => null,
            'message' => null,
        ];

        $session = GameSession::create([
            'type' => GameType::AppleMatch,
            'mode' => GameMode::Online,
            'settings' => ['target_score' => $this->targetScore, 'deck_id' => $deck->id],
            'state' => $state,
            'started_at' => now(),
        ]);

        $this->room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);

        RoomGameStarting::dispatch($this->room);

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
        $this->redirect(route('apple-match.play', ['room' => $this->room->code]));
    }
};
?>

<x-slot:title>Best Match Room — Low Stim Play</x-slot>

<div class="flex flex-col items-center" style="gap: inherit;">
    <div class="heading">Best Match Room</div>

    @if ($this->switchedElsewhere)
        <div class="setup">
            <p class="status-line">The host switched to {{ $this->switchedElsewhere->label() }}.</p>
            <a href="{{ route($this->switchedElsewhere->roomRoute(), ['code' => $room->code]) }}">
                <button type="button">Join</button>
            </a>
        </div>
    @elseif ($lobbyOpened)
        @if ($startError)
            <p class="status-line" style="color: var(--warn);">{{ $startError }}</p>
        @endif

        <livewire:game-lobby :room="$room" :current-game="GameType::AppleMatch" />
    @elseif ($this->isHost)
        <div class="setup">
            <div class="setup-row">
                <div class="label">Points to Win</div>
                <div class="stepper">
                    <button type="button" wire:click="setTargetScore({{ $targetScore - 1 }})" @disabled($targetScore <= self::MIN_TARGET) aria-label="Fewer points">&minus;</button>
                    <div class="stepper-value">{{ $targetScore }}</div>
                    <button type="button" wire:click="setTargetScore({{ $targetScore + 1 }})" @disabled($targetScore >= self::MAX_TARGET) aria-label="More points">+</button>
                </div>
            </div>
            <div class="setup-note">First to win this many green cards wins the game. Needs at least {{ self::MIN_PLAYERS }} players to start.</div>

            <button type="button" id="open-lobby-btn" wire:click="openLobby">Open Lobby</button>
        </div>
    @else
        <div class="setup">
            <p class="status-line">Waiting for the host to finish setting up&hellip;</p>
        </div>
    @endif
</div>
