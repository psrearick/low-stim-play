<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\PunchlineMatchupStatus;
use App\Enums\PunchlinePhase;
use App\Enums\RoomStatus;
use App\Events\RoomGameStarting;
use App\Models\GameSession;
use App\Models\PunchlinePrompt;
use App\Models\Room;
use App\Models\RoomSeat;
use App\Models\User;
use App\Services\Punchline\PairingService;
use App\Services\Punchline\ScoringService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public Room $room;

    /**
     * Set only on the host's own connection when onRoomStarting() below
     * declines to actually start -- 'room-game-starting' is a local,
     * non-broadcast dispatch, so this never reaches anyone else, same
     * reasoning as Best Match's own $startError.
     */
    public ?string $startError = null;

    public function mount(string $code): void
    {
        $this->room = Room::notExpired()->where('code', strtoupper($code))->firstOrFail();

        // No pass-and-play setup screen at all (docs/specs/punchline.md
        // section 1), same reasoning as Best Match's own room page -- a
        // first-time visitor always needs a guest account provisioned here.
        if (Auth::guest()) {
            Auth::login(User::createGuest(request()->cookie('guest_name')));

            if (request()->hasSession()) {
                request()->session()->regenerate();
            }
        }

        $this->room->addMember(Auth::user());

        if (Auth::id() === $this->room->host_user_id && $this->room->game_session_id === null) {
            $this->room->markPendingGame(GameType::Punchline);
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
     * Builds round 1's matchups and creates the shared round state. Falling
     * outside PairingService::MIN_PLAYERS-MAX_PLAYERS is a completely
     * ordinary occurrence here (unlike every other room game, this one
     * enforces a real maximum too -- the pairing algorithm is only
     * defined/tested for that range, see docs/specs/punchline.md section
     * 2), so this sets $startError, visible to the host on this same
     * request/response, rather than silently doing nothing.
     *
     * @param  array<int, array{id: int, name: string, is_host: bool, ready: bool}>  $players
     */
    public function onRoomStarting(array $players, PairingService $pairingService, ScoringService $scoringService): void
    {
        $this->room->refresh();

        if ($this->room->game_session_id !== null) {
            $this->goToGame();

            return;
        }

        $seats = array_values($players);
        $playerCount = count($seats);

        if ($playerCount < PairingService::MIN_PLAYERS || $playerCount > PairingService::MAX_PLAYERS) {
            $this->startError = 'Punchline needs between '.PairingService::MIN_PLAYERS.' and '
                .PairingService::MAX_PLAYERS." players to start \u{2014} {$playerCount} here right now.";

            return;
        }

        if (PunchlinePrompt::count() < $pairingService->promptCountForRound($playerCount)) {
            $this->startError = 'Punchline\'s prompt bank hasn\'t been seeded yet -- ask whoever runs this deployment to run its database seeder.';

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

        $seatGroups = $pairingService->generateRoundMatchups($playerCount);
        $promptIds = $this->pickPrompts(count($seatGroups), []);
        $prompts = PunchlinePrompt::whereIn('id', $promptIds)->get()->keyBy('id');

        $matchups = [];
        $usedPromptIds = [];

        foreach ($seatGroups as $index => $seatIndices) {
            $promptId = $promptIds[$index];
            $usedPromptIds[] = $promptId;

            $matchups[] = [
                'id' => "r1-{$index}",
                'promptId' => $promptId,
                'promptText' => $prompts[$promptId]->text,
                'seatIndices' => $seatIndices,
                'answersPerSeat' => 1,
                'status' => PunchlineMatchupStatus::Writing->value,
                'submittedCounts' => [],
                'votedSeats' => [],
                'answers' => [],
                'results' => null,
            ];
        }

        $state = [
            'round' => 1,
            'phase' => PunchlinePhase::Writing->value,
            'pool' => $scoringService->poolForRound(1),
            'seats' => array_map(
                fn (array $player, int $index) => ['seatIndex' => $index, 'name' => $player['name']],
                $seats,
                array_keys($seats),
            ),
            'scores' => array_fill(0, $playerCount, 0),
            'pointsByRound' => [],
            'sweepsWon' => [],
            'writingEndsAt' => now()->addSeconds(75)->timestamp,
            'votingEndsAt' => null,
            'usedPromptIds' => $usedPromptIds,
            'matchups' => $matchups,
            'message' => null,
        ];

        $session = GameSession::create([
            'type' => GameType::Punchline,
            'mode' => GameMode::Online,
            'settings' => [],
            'state' => $state,
            'started_at' => now(),
        ]);

        $this->room->update(['status' => RoomStatus::Active, 'game_session_id' => $session->id]);

        RoomGameStarting::dispatch($this->room);

        $this->goToGame();
    }

    /**
     * @param  list<int>  $excludeIds
     * @return list<int>
     */
    private function pickPrompts(int $count, array $excludeIds): array
    {
        $ids = PunchlinePrompt::whereNotIn('id', $excludeIds)
            ->inRandomOrder()
            ->limit($count)
            ->pluck('id')
            ->all();

        if (count($ids) < $count) {
            $ids = array_merge($ids, PunchlinePrompt::whereNotIn('id', $ids)
                ->inRandomOrder()
                ->limit($count - count($ids))
                ->pluck('id')
                ->all());
        }

        return $ids;
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
        $this->redirect(route('punchline.play', ['room' => $this->room->code]));
    }
};
?>

<x-slot:title>Punchline Room — Low Stim Play</x-slot>

<div class="flex flex-col items-center" style="gap: inherit;">
    <div class="heading">Punchline Room</div>

    @if ($this->isHost)
        <div class="setup">
            <div class="setup-note">
                A fixed 3-round game &mdash; needs {{ \App\Services\Punchline\PairingService::MIN_PLAYERS }}
                to {{ \App\Services\Punchline\PairingService::MAX_PLAYERS }} players to start.
            </div>
        </div>
    @endif

    @if ($startError)
        <p class="status-line" style="color: var(--warn);">{{ $startError }}</p>
    @endif

    <livewire:game-lobby :room="$room" :current-game="GameType::Punchline" />
</div>
