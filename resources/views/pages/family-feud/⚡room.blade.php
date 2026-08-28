<?php

use App\Enums\FamilyFeudPhase;
use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Events\RoomGameStarting;
use App\Events\RoomGameSwitched;
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

    /**
     * True once the host has clicked "Start Game" in the lobby and is now
     * on the team-assignment screen below, before the round itself
     * actually begins -- see onRoomStarting()/startRound(). Only ever
     * true for the host's own connection: 'room-game-starting' is a local
     * (non-broadcast) Livewire dispatch that only reaches the request
     * that triggered it, and game-lobby's own startGame() already aborts
     * for a non-host caller.
     */
    public bool $assigningTeams = false;

    /**
     * The ready players game-lobby's startGame() handed off, snapshotted
     * once at that moment -- see onRoomStarting().
     *
     * @var list<array{id: int, name: string, is_host: bool, ready: bool}>
     */
    public array $readyPlayers = [];

    /**
     * user_id => team_index (0 or 1), host-editable on the assignment
     * screen below.
     *
     * @var array<int, int>
     */
    public array $teamAssignments = [];

    /**
     * user_id => is this player currently their team's captain. Exactly
     * one true per non-empty team, enforced by ensureEachTeamHasACaptain()
     * after every move.
     *
     * @var array<int, bool>
     */
    public array $captainAssignments = [];

    /**
     * Gates <livewire:game-lobby> -- false the entire time the host is
     * still on this page's own config fields above, so nobody else gets
     * notified/can join until the host explicitly opens the lobby (see
     * openLobby() below). Re-derived from Room::pendingGameType() on every
     * mount() rather than stored anywhere new, so a reload or a return
     * visit lands back in the right phase automatically. Unrelated to
     * $assigningTeams above -- that's a later, third stage that only
     * begins once game-lobby's own Start Game has already fired.
     */
    public bool $lobbyOpened = false;

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

        // See pages::yahtzee.room's identical check for why this matters:
        // a reconnecting player whose device dropped at the exact moment
        // the host clicked "Start" would otherwise never receive
        // RoomGameStarting and get stuck looking at a stale lobby. Note
        // this only covers *before* team assignment (game_session_id is
        // still null the whole time the host is on that screen) -- a host
        // who reloads mid-assignment loses their in-progress picks and
        // lands back on the plain lobby instead, an accepted limitation
        // (see docs/DEVLOG.md) rather than persisting draft assignments.
        $this->redirectIfAlreadyStarted();

        // Reflects whatever's already true rather than assuming this is a
        // fresh visit -- the host reloading after already clicking Open
        // Lobby (or a member arriving after that already happened) both
        // need to land past the config screen, not back at square one.
        $this->lobbyOpened = $this->room->pendingGameType() === GameType::FamilyFeud;
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

        $this->room->markPendingGame(GameType::FamilyFeud);
        RoomGameSwitched::dispatch($this->room, GameType::FamilyFeud);
        $this->lobbyOpened = true;
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
        $this->lobbyOpened = $this->room->pendingGameType() === GameType::FamilyFeud;
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

        return $pending !== null && $pending !== GameType::FamilyFeud ? $pending : null;
    }

    /**
     * Only ever reaches the host (see $assigningTeams's own docblock).
     * Doesn't create the GameSession/seats itself any more -- it just
     * snapshots the ready players and switches this connection to the
     * team-assignment screen below; startRound() is what actually
     * finalizes and starts the round once the host confirms teams.
     *
     * @param  array<int, array{id: int, name: string, is_host: bool, ready: bool}>  $players
     */
    public function onRoomStarting(array $players): void
    {
        $this->room->refresh();

        // Already started (e.g. a late-arriving duplicate dispatch) --
        // just go, rather than re-entering team assignment over a game
        // that's already running.
        if ($this->room->game_session_id !== null) {
            $this->goToGame();

            return;
        }

        // Mirrors startGame()'s own local guard against a fresh/
        // misconfigured deployment (seeder never run) -- there's no
        // message-passing path back into the lobby from here, so this
        // just declines to proceed rather than crashing the very next
        // render the way an empty $questions array used to.
        if (FamilyFeudQuestion::count() === 0) {
            return;
        }

        $this->readyPlayers = array_values($players);

        // Alternate seats onto the two teams by join order (even index ->
        // team 0, odd -> team 1) as a starting point -- the same "seat
        // index = join order" simplicity Yahtzee's own seat assignment
        // uses -- but the host can still reshuffle players and captains
        // below before confirming.
        $this->teamAssignments = [];
        $this->captainAssignments = [];

        foreach ($this->readyPlayers as $index => $player) {
            $this->teamAssignments[$player['id']] = $index % 2;
            // Explicit false for every player up front (rather than
            // relying on the `?? false` fallback used elsewhere) so the
            // array always has one real entry per ready player -- makes
            // the state easy to assert on/reason about in full.
            $this->captainAssignments[$player['id']] = false;
        }

        $this->ensureEachTeamHasACaptain();

        $this->assigningTeams = true;
    }

    public function moveToTeam(int $userId, int $teamIndex): void
    {
        abort_unless(Auth::id() === $this->room->host_user_id, 403);
        abort_unless(in_array($teamIndex, [0, 1], true), 403);

        if (! array_key_exists($userId, $this->teamAssignments)) {
            return;
        }

        $this->teamAssignments[$userId] = $teamIndex;

        // A moved player always loses captaincy on their old team --
        // they only become their new team's captain via an explicit
        // "Make Captain" tap, never automatically, since the team they're
        // joining may already have one.
        $this->captainAssignments[$userId] = false;

        $this->ensureEachTeamHasACaptain();
    }

    public function makeCaptain(int $userId): void
    {
        abort_unless(Auth::id() === $this->room->host_user_id, 403);

        $teamIndex = $this->teamAssignments[$userId] ?? null;

        if ($teamIndex === null) {
            return;
        }

        foreach ($this->teamAssignments as $otherId => $otherTeam) {
            if ($otherTeam === $teamIndex) {
                $this->captainAssignments[$otherId] = false;
            }
        }

        $this->captainAssignments[$userId] = true;
    }

    /**
     * Whichever team lost its captain (moved away, or never had one
     * assigned yet) gets its first remaining member promoted automatically
     * -- a non-empty team should never end up with zero captains, since
     * Steal requires one. An empty team is simply skipped; canStartRound
     * below is what actually keeps the host from confirming an unbalanced
     * game rather than silently patching one back together here.
     */
    private function ensureEachTeamHasACaptain(): void
    {
        foreach ([0, 1] as $teamIndex) {
            $memberIds = array_keys(array_filter(
                $this->teamAssignments,
                fn (int $t) => $t === $teamIndex,
            ));

            if ($memberIds === []) {
                continue;
            }

            $hasCaptain = collect($memberIds)->contains(fn (int $id) => $this->captainAssignments[$id] ?? false);

            if (! $hasCaptain) {
                $this->captainAssignments[$memberIds[0]] = true;
            }
        }
    }

    /**
     * @return list<array{id: int, name: string, is_host: bool, ready: bool}>
     */
    public function teamMembers(int $teamIndex): array
    {
        return array_values(array_filter(
            $this->readyPlayers,
            fn (array $player) => ($this->teamAssignments[$player['id']] ?? null) === $teamIndex,
        ));
    }

    public function getCanStartRoundProperty(): bool
    {
        return $this->teamMembers(0) !== [] && $this->teamMembers(1) !== [];
    }

    /**
     * Finalizes the host's team assignments into a real GameSession/
     * RoomSeat rows and starts the round -- the actual creation logic
     * onRoomStarting() used to run immediately, now gated behind the
     * team-assignment screen instead.
     */
    public function startRound(): void
    {
        abort_unless(Auth::id() === $this->room->host_user_id, 403);
        abort_unless($this->assigningTeams, 403);
        abort_unless($this->canStartRound, 403);

        $this->room->refresh();

        if ($this->room->game_session_id === null) {
            $teamSeats = [[], []];
            $seatAssignments = [];

            foreach ($this->readyPlayers as $seatIndex => $player) {
                $teamIndex = $this->teamAssignments[$player['id']];
                $isCaptain = $this->captainAssignments[$player['id']] ?? false;

                $teamSeats[$teamIndex][] = [
                    'seatIndex' => $seatIndex,
                    'name' => $player['name'],
                    'isCaptain' => $isCaptain,
                ];

                $seatAssignments[$seatIndex] = [
                    'user_id' => $player['id'],
                    'team_index' => $teamIndex,
                    'is_captain' => $isCaptain,
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

            // Cleared first -- see the identical comment in
            // pages::yahtzee.room::onRoomStarting() for why: a replay, or a
            // switch to a different game and back (GameType::roomRoute()),
            // re-runs this branch, and without clearing first each pass
            // added a second set of seat rows on top of the last game's.
            RoomSeat::where('room_id', $this->room->id)->delete();

            foreach ($seatAssignments as $seatIndex => $assignment) {
                RoomSeat::create([
                    'room_id' => $this->room->id,
                    'seat_index' => $seatIndex,
                    'user_id' => $assignment['user_id'],
                    'team_index' => $assignment['team_index'],
                    'is_captain' => $assignment['is_captain'],
                ]);
            }

            $this->room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);

            RoomGameStarting::dispatch($this->room);
        }

        $this->assigningTeams = false;
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

<x-slot:title>Survey Says Room — Low Stim Play</x-slot>

<div class="flex flex-col items-center" style="gap: inherit;">
    <div class="heading">Survey Says Room</div>

    @if ($assigningTeams)
        <div class="setup">
            <div class="setup-note">Tap "Move to {{ $teamNames[1] ?: 'Team 2' }}"/"Move to {{ $teamNames[0] ?: 'Team 1' }}" to reassign a player, and "Make Captain" to change who can attempt a steal for that team.</div>

            <div class="flex flex-col gap-4 w-full">
                @foreach ([0, 1] as $teamIndex)
                    <div class="flex flex-col gap-2 w-full">
                        <div class="label">{{ $teamNames[$teamIndex] ?: 'Team '.($teamIndex + 1) }}</div>
                        <ul class="w-full flex flex-col gap-2">
                            @forelse ($this->teamMembers($teamIndex) as $player)
                                <li wire:key="assign-{{ $player['id'] }}" class="flex items-center justify-between w-full status-line gap-2">
                                    <span>
                                        {{ $player['name'] }}
                                        @if ($captainAssignments[$player['id']] ?? false)
                                            <span style="color: var(--accent);">(Captain)</span>
                                        @endif
                                    </span>
                                    <span class="flex gap-2">
                                        @unless ($captainAssignments[$player['id']] ?? false)
                                            <button type="button" wire:click="makeCaptain({{ $player['id'] }})">Make Captain</button>
                                        @endunless
                                        <button type="button" wire:click="moveToTeam({{ $player['id'] }}, {{ 1 - $teamIndex }})">
                                            Move to {{ $teamNames[1 - $teamIndex] ?: 'Team '.(2 - $teamIndex) }}
                                        </button>
                                    </span>
                                </li>
                            @empty
                                <li class="status-line">No players yet.</li>
                            @endforelse
                        </ul>
                    </div>
                @endforeach
            </div>

            <button type="button" wire:click="startRound" @disabled(! $this->canStartRound)>Start Round</button>

            @unless ($this->canStartRound)
                <p class="status-line" style="color: var(--warn);">Each team needs at least one player.</p>
            @endunless
        </div>
    @elseif ($this->switchedElsewhere)
        <div class="setup">
            <p class="status-line">The host switched to {{ $this->switchedElsewhere->label() }}.</p>
            <a href="{{ route($this->switchedElsewhere->roomRoute(), ['code' => $room->code]) }}">
                <button type="button">Join</button>
            </a>
        </div>
    @elseif ($lobbyOpened)
        <livewire:game-lobby :room="$room" :current-game="GameType::FamilyFeud" />
    @elseif ($this->isHost)
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
            <div class="setup-note">Starting the game opens a team-assignment screen next, before the first round begins.</div>

            <button type="button" id="open-lobby-btn" wire:click="openLobby">Open Lobby</button>
        </div>
    @else
        <div class="setup">
            <p class="status-line">Waiting for the host to finish setting up&hellip;</p>
        </div>
    @endif
</div>
