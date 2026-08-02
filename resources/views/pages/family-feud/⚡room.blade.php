<?php

use App\Enums\FamilyFeudPhase;
use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Events\RoomGameStarting;
use App\Models\FamilyFeudQuestion;
use App\Models\GameSession;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public const MIN_ROUNDS = 1;

    public const DEFAULT_ROUNDS = 5;

    public Room $room;

    public int $rounds = self::DEFAULT_ROUNDS;

    /**
     * @var array{0: string, 1: string}
     */
    public array $teamNames = ['Team 1', 'Team 2'];

    public function mount(string $code): void
    {
        $this->room = Room::notExpired()->where('code', strtoupper($code))->firstOrFail();
        $this->rounds = min($this->rounds, $this->maxRounds());

        // Arriving here directly (a shared room URL) rather than through the
        // /family-feud setup screen's own "Join" form -- that form already
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

        // See pages::yahtzee.room's identical check for why this matters:
        // a reconnecting player whose device dropped at the exact moment
        // the host clicked "Start" would otherwise never receive
        // RoomGameStarting and get stuck looking at a stale lobby.
        $this->redirectIfAlreadyStarted();
    }

    private function redirectIfAlreadyStarted(): void
    {
        if ($this->room->status === RoomStatus::Active && $this->room->game_session_id !== null) {
            $this->goToGame();
        }
    }

    public function setRounds(int $rounds): void
    {
        $this->rounds = max(self::MIN_ROUNDS, min($this->maxRounds(), $rounds));
    }

    public function maxRounds(): int
    {
        return max(self::MIN_ROUNDS, intdiv(FamilyFeudQuestion::count(), 2));
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
            // Mirrors startGame()'s own local guard against a fresh/
            // misconfigured deployment (seeder never run) -- there's no
            // message-passing path back into the lobby from here, so this
            // just declines to create a session rather than crashing the
            // very next render the way an empty $questions array used to.
            if (FamilyFeudQuestion::count() === 0) {
                return;
            }

            $seats = array_values($players);

            // Alternate seats onto the two teams by join order (even index
            // -> team 0, odd -> team 1) -- the same "seat index = join
            // order" simplicity Yahtzee's own seat assignment uses. The
            // first seat on each team (index 0 and 1) is automatically
            // that team's captain -- no separate team/captain-picking UI.
            $teamSeats = [[], []];

            foreach ($seats as $index => $player) {
                $teamSeats[$index % 2][] = [
                    'seatIndex' => $index,
                    'name' => $player['name'],
                    'isCaptain' => $index < 2,
                ];
            }

            $chosen = FamilyFeudQuestion::inRandomOrder()
                ->limit($this->rounds * 2)
                ->get()
                ->map(fn (FamilyFeudQuestion $question) => $question->toSafeArray())
                ->all();

            $state = [
                'teams' => [
                    ['name' => $this->resolvedTeamName(0), 'score' => 0],
                    ['name' => $this->resolvedTeamName(1), 'score' => 0],
                ],
                'teamSeats' => $teamSeats,
                'teamTurnPointer' => [0, 0],
                'questions' => $chosen,
                'currentQuestionIndex' => 0,
                'revealed' => [],
                'strikes' => 0,
                'pot' => 0,
                'controllingTeam' => 0,
                'phase' => FamilyFeudPhase::Play->value,
                'roundHistory' => array_fill(0, $this->rounds, [0, 0]),
                'roundEndTitle' => '',
                'message' => null,
                'messageIsError' => false,
            ];

            $session = GameSession::create([
                'type' => GameType::FamilyFeud,
                'mode' => GameMode::Online,
                'settings' => ['rounds' => $this->rounds],
                'state' => $state,
                'started_at' => now(),
            ]);

            foreach ($seats as $index => $player) {
                RoomSeat::create([
                    'room_id' => $this->room->id,
                    'seat_index' => $index,
                    'user_id' => $player['id'],
                    'team_index' => $index % 2,
                    'is_captain' => $index < 2,
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

    private function resolvedTeamName(int $index): string
    {
        $typed = trim($this->teamNames[$index] ?? '');

        return $typed !== '' ? $typed : 'Team '.($index + 1);
    }

    public function getIsHostProperty(): bool
    {
        return Auth::id() === $this->room->host_user_id;
    }

    private function goToGame(): void
    {
        $this->redirect(route('family-feud.play', ['room' => $this->room->code]));
    }
};
?>

<x-slot:title>Family Feud Room — Low Stim Play</x-slot>

<div class="flex flex-col items-center" style="gap: inherit;">
    <div class="heading">Family Feud Room</div>

    @if ($this->isHost)
        <div class="setup">
            <div class="team-inputs">
                <div class="team-input-row">
                    <label for="team0">Team 1</label>
                    <input type="text" id="team0" wire:model="teamNames.0" maxlength="24" placeholder="Team 1">
                </div>
                <div class="team-input-row">
                    <label for="team1">Team 2</label>
                    <input type="text" id="team1" wire:model="teamNames.1" maxlength="24" placeholder="Team 2">
                </div>
            </div>

            <div class="setup-row">
                <label>Rounds</label>
                <div class="stepper">
                    <button type="button" wire:click="setRounds({{ $rounds - 1 }})" @disabled($rounds <= self::MIN_ROUNDS) aria-label="Fewer rounds">&minus;</button>
                    <div class="stepper-value">{{ $rounds }}</div>
                    <button type="button" wire:click="setRounds({{ $rounds + 1 }})" @disabled($rounds >= $this->maxRounds()) aria-label="More rounds">+</button>
                </div>
            </div>
            <div class="setup-note">Players are split into two teams by join order -- whoever's first to ready up on each team is that team's captain (needed only for steal attempts).</div>
        </div>
    @endif

    <livewire:game-lobby :room="$room" />
</div>
