<?php

use App\Enums\GameMode;
use App\Enums\GameResult;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\Room;
use App\Services\Boggle\BoardGenerator;
use App\Services\Boggle\Scorer;
use App\Services\Boggle\Solver;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public int $size = 4;

    #[Url]
    public int $minutes = 3;

    /**
     * Sharing a seed makes an identical board reproducible — used by
     * online rooms (everyone gets the host's seed) and async challenges
     * (the same board, played solo at any time). Omitted, a fresh random
     * board is generated instead.
     */
    #[Url]
    public ?int $seed = null;

    /**
     * The online room this play-through belongs to, if any. When set, the
     * board's size/minutes/seed all come from the room's shared
     * GameSession instead of the (still-present, but now ignored) query
     * params above, and ending the game joins that same GameSession
     * instead of creating a new one.
     */
    #[Url]
    public ?string $room = null;

    /**
     * An existing solo game's session id, shared as an async challenge
     * link (`route('boggle.share', ...)`). When set, this play-through
     * joins that same GameSession (same board, played at any time) rather
     * than creating a new one, exactly like `$room` does for online play.
     */
    #[Url]
    public ?int $challenge = null;

    public ?int $gameSessionId = null;

    /**
     * True once this play-through is confirmed tied to an existing shared
     * session (a `$room` or a `$challenge`), as opposed to a plain solo
     * game that merely *becomes* shareable after the fact. Distinct from
     * `gameSessionId` because that gets populated for plain solo games too
     * once they end (to build their own "Challenge a friend" link) — this
     * flag is what actually gates session-reuse and result ranking.
     */
    public bool $isSharedSession = false;

    /**
     * @var array{size: int, seed: int, cells: list<array<string, mixed>>, neighbors: list<list<int>>}
     */
    public array $board = [];

    /**
     * Indices of the board cells selected so far, in order.
     *
     * @var list<int>
     */
    public array $path = [];

    /**
     * Found words and the points they were worth, keyed by word.
     *
     * @var array<string, int>
     */
    public array $foundWords = [];

    public int $score = 0;

    public int $startedAt;

    public ?int $endsAt = null;

    public bool $ended = false;

    /**
     * @var list<string>|null
     */
    public ?array $allValidWords = null;

    public ?string $message = null;

    public function mount(BoardGenerator $boardGenerator): void
    {
        if ($this->room !== null) {
            $session = Room::where('code', $this->room)->firstOrFail()->gameSession;
            $this->isSharedSession = true;
        } elseif ($this->challenge !== null) {
            $session = GameSession::where('type', GameType::Boggle)->findOrFail($this->challenge);
            $this->isSharedSession = true;
        }

        if ($this->isSharedSession) {
            $this->gameSessionId = $session->id;
            $this->size = $session->settings['size'];
            $this->minutes = $session->settings['minutes'];
            $this->seed = $session->settings['seed'];
        }

        $this->size = in_array($this->size, [4, 5], true) ? $this->size : 4;
        $this->minutes = max(0, $this->minutes);

        $this->board = $boardGenerator->generate($this->size, $this->seed);
        $this->startedAt = now()->timestamp;
        $this->endsAt = $this->minutes > 0 ? now()->addMinutes($this->minutes)->timestamp : null;
    }

    public function selectCell(int $index): void
    {
        if ($this->ended || in_array($index, $this->path, true)) {
            return;
        }

        if ($this->path === []) {
            $this->path[] = $index;

            return;
        }

        $last = $this->path[count($this->path) - 1];

        if (in_array($index, $this->board['neighbors'][$last], true)) {
            $this->path[] = $index;
        }
    }

    public function clearPath(): void
    {
        $this->path = [];
    }

    public function submitWord(Solver $solver, Scorer $scorer): void
    {
        if ($this->ended || $this->path === []) {
            return;
        }

        $word = implode('', array_map(
            fn (int $index) => $this->board['cells'][$index]['match'],
            $this->path,
        ));

        $this->path = [];

        if (mb_strlen($word) < Scorer::MIN_WORD_LENGTH) {
            $this->message = 'Too short.';

            return;
        }

        if (isset($this->foundWords[$word])) {
            $this->message = 'Already found.';

            return;
        }

        if (! $solver->isValidWord($word)) {
            $this->message = 'Not a word.';

            return;
        }

        $points = $scorer->pointsFor($word);
        $this->foundWords[$word] = $points;
        $this->score += $points;
        $this->message = "+{$points} point".($points === 1 ? '' : 's').'!';
    }

    public function tick(): void
    {
        if (! $this->ended && $this->endsAt !== null && now()->timestamp >= $this->endsAt) {
            $this->endGame();
        }
    }

    public function endGame(Solver $solver): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;
        $this->path = [];
        $this->allValidWords = $solver->solve($this->board);

        if ($this->isSharedSession) {
            $session = GameSession::find($this->gameSessionId);
            $session->update(['ended_at' => now()]);
        } else {
            $session = GameSession::create([
                'type' => GameType::Boggle,
                'mode' => GameMode::Solo,
                'settings' => ['size' => $this->size, 'seed' => $this->board['seed'], 'minutes' => $this->minutes],
                'started_at' => now()->setTimestamp($this->startedAt),
                'ended_at' => now(),
            ]);
            $this->gameSessionId = $session->id;
        }

        GameParticipant::create([
            'game_session_id' => $session->id,
            'user_id' => Auth::id(),
            'guest_name' => Auth::check() ? null : 'Guest',
            'score' => $this->score,
            'stats' => ['words_found' => count($this->foundWords)],
        ]);

        if ($this->isSharedSession) {
            $this->rankParticipants($session);
        }
    }

    /**
     * Ranks every participant recorded so far for an online game session:
     * the current highest score wins, everyone else loses, and a tie at
     * the top is a draw for all of them. Re-run every time a participant
     * finishes, so it's self-correcting as more players finish and
     * eventually converges once everyone has.
     */
    private function rankParticipants(GameSession $session): void
    {
        $participants = $session->participants()->get();
        $topScore = $participants->max('score');
        $winners = $participants->where('score', $topScore)->count();

        foreach ($participants as $participant) {
            $result = match (true) {
                $participant->score !== $topScore => GameResult::Loss,
                $winners > 1 => GameResult::Draw,
                default => GameResult::Win,
            };

            $participant->update(['result' => $result]);
        }
    }

    public function resetGame(BoardGenerator $boardGenerator): void
    {
        if ($this->isSharedSession) {
            return;
        }

        $this->board = $boardGenerator->generate($this->size);
        $this->path = [];
        $this->foundWords = [];
        $this->score = 0;
        $this->startedAt = now()->timestamp;
        $this->endsAt = $this->minutes > 0 ? now()->addMinutes($this->minutes)->timestamp : null;
        $this->ended = false;
        $this->allValidWords = null;
        $this->message = null;
    }

    /**
     * Other players' final scores for this same shared session, for the
     * async-challenge results comparison. Empty until they've also ended.
     *
     * @return \Illuminate\Support\Collection<int, GameParticipant>
     */
    public function getOtherParticipantsProperty()
    {
        if (! $this->ended || $this->gameSessionId === null) {
            return collect();
        }

        return GameParticipant::with('user')
            ->where('game_session_id', $this->gameSessionId)
            ->when(Auth::check(), fn ($query) => $query->where('user_id', '!=', Auth::id()))
            ->get();
    }

    public function getRemainingSecondsProperty(): ?int
    {
        if ($this->endsAt === null) {
            return null;
        }

        return max(0, $this->endsAt - now()->timestamp);
    }
};
?>

<div class="flex flex-col items-center gap-6 w-full max-w-2xl">
    @if (! $ended && $endsAt !== null)
        <div wire:poll.1s="tick"></div>
    @endif

    <div class="flex items-center justify-between w-full max-w-md text-sm text-text/80">
        <a href="{{ route('boggle.lobby') }}" class="hover:text-text">&larr; Home</a>
        <span>Score: <span class="text-accent font-bold">{{ $score }}</span></span>
        <span>
            @if ($this->remainingSeconds === null)
                Untimed
            @else
                {{ sprintf('%d:%02d', intdiv($this->remainingSeconds, 60), $this->remainingSeconds % 60) }}
            @endif
        </span>
    </div>

    @if (! $ended)
        <div class="grid gap-2" style="grid-template-columns: repeat({{ $size }}, minmax(0, 1fr));">
            @foreach ($board['cells'] as $cell)
                <button type="button" wire:click="selectCell({{ $cell['index'] }})"
                    class="aspect-square w-14 sm:w-16 rounded border-2 font-bold text-xl uppercase
                        {{ in_array($cell['index'], $path, true) ? 'bg-accent text-bg border-accent' : 'bg-surface text-text border-surface-border' }}">
                    {{ $cell['display'] }}
                </button>
            @endforeach
        </div>

        <div class="flex flex-col items-center gap-2 w-full max-w-md">
            <p class="min-h-6 text-lg tracking-widest uppercase text-text/80">
                {{ implode('', array_map(fn ($i) => $board['cells'][$i]['display'], $path)) }}
            </p>

            @if ($message)
                <p class="text-sm text-accent">{{ $message }}</p>
            @endif

            <div class="flex gap-2 w-full">
                <button type="button" wire:click="clearPath" class="flex-1 rounded px-4 py-2 font-bold bg-surface border-2 border-surface-border text-text">
                    Clear
                </button>
                <button type="button" wire:click="submitWord" class="flex-1 rounded px-4 py-2 font-bold bg-accent text-bg">
                    Submit
                </button>
            </div>

            <button type="button" wire:click="endGame" class="text-sm text-text/65 hover:text-text">
                End game
            </button>
        </div>

        <ul class="flex flex-wrap gap-2 justify-center text-sm text-text/80">
            @foreach ($foundWords as $word => $points)
                <li wire:key="found-{{ $word }}" class="rounded bg-surface border border-surface-border px-2 py-1">
                    {{ strtoupper($word) }} ({{ $points }})
                </li>
            @endforeach
        </ul>
    @else
        <livewire:boggle-reveal :board="$board" :found-words="$foundWords" :all-valid-words="$allValidWords" :score="$score" />

        @if ($challenge !== null)
            <div class="flex flex-col items-center gap-2 w-full max-w-md text-sm text-text/80">
                @forelse ($this->otherParticipants as $participant)
                    <p>{{ $participant->user->name ?? $participant->guest_name }} scored {{ $participant->score }}.</p>
                @empty
                    <p class="text-text/65">Waiting for the other player to finish.</p>
                @endforelse
            </div>
        @endif

        @if ($room !== null)
            <a href="{{ route('boggle.room', ['code' => $room]) }}" class="w-full max-w-md text-center rounded px-6 py-2 font-bold tracking-wide bg-accent text-bg">
                Back to room
            </a>
        @elseif ($challenge !== null)
            <a href="{{ route('boggle.lobby') }}" class="w-full max-w-md text-center rounded px-6 py-2 font-bold tracking-wide bg-accent text-bg">
                Back home
            </a>
        @else
            <div class="flex flex-col gap-2 w-full max-w-md">
                <div class="flex gap-2 w-full">
                    <input type="text" readonly value="{{ route('boggle.share', ['gameSession' => $gameSessionId]) }}"
                        onclick="this.select()" class="flex-1 rounded border-2 border-surface-border bg-bg text-text px-3 py-2 text-sm">
                    <button type="button" data-copy="{{ route('boggle.share', ['gameSession' => $gameSessionId]) }}"
                        class="rounded px-4 py-2 font-bold bg-surface border-2 border-surface-border text-text">
                        Copy
                    </button>
                </div>

                <button type="button" wire:click="resetGame" class="w-full rounded px-6 py-2 font-bold tracking-wide bg-accent text-bg">
                    New game
                </button>
            </div>
        @endif
    @endif
</div>
