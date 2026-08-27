<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\HoaxPhase;
use App\Enums\RoomStatus;
use App\Events\RoomGameStarting;
use App\Models\GameSession;
use App\Models\HoaxQuestion;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use App\Services\Hoax\ScoringService;
use App\Services\SubmitVote\BallotService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public const int MIN_PLAYERS = 3;

    public const int MAX_PLAYERS = 8;

    /**
     * Same duration as pages::hoax.play's own ROUND_COUNT constant --
     * kept as a literal here rather than importing the other page's
     * class just for one constant, matching this app's existing
     * room-vs-play duplication tolerance (see Punchline's own room/play
     * split).
     */
    public const int ROUND_COUNT = 5;

    public Room $room;

    /**
     * Set only on the host's own connection when onRoomStarting() below
     * declines to actually start -- 'room-game-starting' is a local,
     * non-broadcast dispatch, so this never reaches anyone else, same
     * reasoning as Punchline's/Best Match's own $startError.
     */
    public ?string $startError = null;

    public function mount(string $code): void
    {
        $this->room = Room::notExpired()->where('code', strtoupper($code))->firstOrFail();

        // No pass-and-play setup screen at all (docs/specs/hoax.md
        // section 1), same reasoning as Punchline's own room page -- a
        // first-time visitor always needs a guest account provisioned here.
        if (Auth::guest()) {
            Auth::login(User::createGuest(request()->cookie('guest_name')));

            if (request()->hasSession()) {
                request()->session()->regenerate();
            }
        }

        $this->room->addMember(Auth::user());

        if (Auth::id() === $this->room->host_user_id && $this->room->game_session_id === null) {
            $this->room->markPendingGame(GameType::Hoax);
        }

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
     * Builds round 1's shared state and creates the game session. Falling
     * outside MIN_PLAYERS-MAX_PLAYERS, or the question bank not having
     * enough questions for a full ROUND_COUNT-round game, sets
     * $startError (visible to the host on this same request/response)
     * rather than silently doing nothing, same as Punchline's own gate.
     *
     * @param  array<int, array{id: int, name: string, is_host: bool, ready: bool}>  $players
     */
    public function onRoomStarting(array $players, BallotService $ballotService): void
    {
        $this->room->refresh();

        if ($this->room->game_session_id !== null) {
            $this->goToGame();

            return;
        }

        $seats = array_values($players);
        $playerCount = count($seats);

        if ($playerCount < self::MIN_PLAYERS || $playerCount > self::MAX_PLAYERS) {
            $this->startError = 'Hoax needs between '.self::MIN_PLAYERS.' and '
                .self::MAX_PLAYERS." players to start \u{2014} {$playerCount} here right now.";

            return;
        }

        if (HoaxQuestion::count() < self::ROUND_COUNT) {
            $this->startError = 'Hoax\'s question bank hasn\'t been seeded yet -- ask whoever runs this deployment to run its database seeder.';

            return;
        }

        $this->startError = null;

        RoomSeat::where('room_id', $this->room->id)->delete();

        foreach ($seats as $index => $player) {
            RoomSeat::create([
                'room_id' => $this->room->id,
                'seat_index' => $index,
                'user_id' => $player['id'],
            ]);
        }

        $question = $this->pickQuestion([]);

        $state = [
            'round' => 1,
            'phase' => HoaxPhase::Writing->value,
            'questionId' => $question->id,
            'questionText' => $question->text,
            'seats' => array_map(
                fn (array $player, int $index) => ['seatIndex' => $index, 'name' => $player['name']],
                $seats,
                array_keys($seats),
            ),
            'scores' => array_fill(0, $playerCount, 0),
            'pointsByRound' => [],
            'truthsFound' => [],
            // Same duration as pages::hoax.play's own WRITING_SECONDS
            // constant -- see this class's own ROUND_COUNT docblock for
            // why it's a literal here.
            'writingEndsAt' => now()->addSeconds(90)->timestamp,
            'votingEndsAt' => null,
            'usedQuestionIds' => [$question->id],
            'submittedSeats' => [],
            'votedSeats' => [],
            'answers' => [],
            'results' => null,
            'message' => null,
        ];

        $session = GameSession::create([
            'type' => GameType::Hoax,
            'mode' => GameMode::Online,
            'settings' => [],
            'state' => $state,
            'started_at' => now(),
        ]);

        // The real answer is "authored" by a sentinel seat index that can
        // never collide with a real player (see
        // Hoax\ScoringService::TRUTH_SEAT_INDEX's own docblock) -- written
        // now so it's already sitting in the ballot, camouflaged among the
        // real submissions, by the time writing opens.
        $ballotService->submit(
            "hoax:{$session->id}:round:1",
            ScoringService::TRUTH_SEAT_INDEX,
            'truth',
            $question->answer,
        );

        $this->room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);

        RoomGameStarting::dispatch($this->room);

        $this->goToGame();
    }

    private function pickQuestion(array $excludeIds): HoaxQuestion
    {
        $question = HoaxQuestion::whereNotIn('id', $excludeIds)->inRandomOrder()->first();

        return $question ?? HoaxQuestion::inRandomOrder()->firstOrFail();
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
        $this->redirect(route('hoax.play', ['room' => $this->room->code]));
    }
};
?>

<x-slot:title>Hoax Room — Low Stim Play</x-slot>

<div class="flex flex-col items-center" style="gap: inherit;">
    <div class="heading">Hoax Room</div>

    @if ($this->isHost)
        <div class="setup">
            <div class="setup-note">
                A fixed 5-round game &mdash; needs {{ $this::MIN_PLAYERS }}
                to {{ $this::MAX_PLAYERS }} players to start.
            </div>
        </div>
    @endif

    @if ($startError)
        <p class="status-line" style="color: var(--warn);">{{ $startError }}</p>
    @endif

    <livewire:game-lobby :room="$room" :current-game="GameType::Hoax" />
</div>
