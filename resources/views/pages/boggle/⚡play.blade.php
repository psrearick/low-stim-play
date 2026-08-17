<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\RoomStatus;
use App\Events\RoomGameEnded;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Models\Room;
use App\Services\Boggle\BoardGenerator;
use App\Services\Boggle\Scorer;
use App\Services\Boggle\SharedSessionFinalizer;
use App\Services\Boggle\Solver;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    private const WIDE = ['W', 'M', 'Qu'];

    private const DESCENDER = ['Qu'];

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
     * Indices of the board cells selected so far, in order. Only ever
     * populated as a whole by `submitPath()` -- the real drag/tap
     * interaction (see the @script block below) tracks the in-progress
     * path entirely client-side for a responsive feel, and reports the
     * finished path in a single round trip once a word is ready to submit.
     * `selectCell()`/`clearPath()` below remain as a step-at-a-time
     * fallback API (and are what the test suite drives directly).
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
     * Set by finishGame() when End Game was clicked to stop a *timed* solo
     * game early, before its own clock actually reached zero (see
     * finishGame()'s own docblock) -- distinct from $ended, which is true
     * for every ended game, recorded or not. Drives the reveal screen's
     * "not saved" message and hides the challenge-link share box, since
     * neither a GameSession nor a GameParticipant was ever written for an
     * early-ended game.
     */
    public bool $endedEarly = false;

    /**
     * @var list<string>|null
     */
    public ?array $allValidWords = null;

    public ?string $message = null;

    public function mount(BoardGenerator $boardGenerator, Solver $solver): void
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

        // Rejoining an already-finished shared session (e.g. a page refresh
        // right as the room's game ends) used to silently start a brand
        // new attempt -- $ended defaults false on every fresh mount, with
        // nothing checking whether this user already has a GameParticipant
        // row for this session. That let the same account finish the same
        // shared game twice, creating a second row via finishGame()'s
        // create() call below and corrupting SharedSessionFinalizer's
        // per-word finder counts (a real duplicate pair, same session and
        // user, found in production data -- see docs/DEVLOG.md). Only
        // guarded for authenticated users: a guest's identity isn't stable
        // across a reload (every guest in a session shares user_id ===
        // null), so there's nothing reliable to key a restore on.
        if ($this->isSharedSession && Auth::check()) {
            $participant = GameParticipant::query()
                ->where('game_session_id', $session->id)
                ->where('user_id', Auth::id())
                ->first();

            if ($participant !== null) {
                $this->ended = true;
                $this->score = $participant->score;
                $this->foundWords = $participant->stats['found_words'] ?? [];
                $this->allValidWords = $solver->solve($this->board);
            }
        }
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

    /**
     * Sets the whole path at once (each index re-validated for adjacency
     * and uniqueness server-side, same rules as `selectCell()`) and
     * immediately submits it -- the single round trip the client-side drag
     * interaction makes once a word is ready.
     *
     * @param  list<int>  $path
     */
    public function submitPath(array $path, Solver $solver, Scorer $scorer): void
    {
        if ($this->ended) {
            return;
        }

        $this->path = $this->sanitizePath($path);
        $this->submitWord($solver, $scorer);
    }

    /**
     * @param  list<int>  $path
     * @return list<int>
     */
    private function sanitizePath(array $path): array
    {
        $cellCount = count($this->board['cells']);
        $clean = [];

        foreach ($path as $raw) {
            $index = (int) $raw;

            if ($index < 0 || $index >= $cellCount || in_array($index, $clean, true)) {
                break;
            }

            if ($clean !== [] && ! in_array($index, $this->board['neighbors'][end($clean)], true)) {
                break;
            }

            $clean[] = $index;
        }

        return $clean;
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
        $this->message = null;
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
            "echo-presence:room.{$this->room},RoomGameEnded" => 'onRoomGameEnded',
        ];
    }

    public function endGame(Solver $solver, SharedSessionFinalizer $finalizer): void
    {
        $this->finishGame($solver, $finalizer);
    }

    /**
     * A room-mate's game session changed. Either they just ended their game
     * (this one should cascade-end too), or they finished *after* this one
     * already had -- which means finalizeSharedSession() just recalculated
     * every participant's score using the now-complete participant set, and
     * this player's own $this->score (set back when only a partial set of
     * participants existed) is stale. Refresh it rather than let it keep
     * showing a value computed before all the "shared word" exclusions were
     * known.
     */
    public function onRoomGameEnded(Solver $solver, SharedSessionFinalizer $finalizer): void
    {
        if ($this->ended) {
            $this->refreshMyScore();

            return;
        }

        $this->finishGame($solver, $finalizer);
    }

    private function refreshMyScore(): void
    {
        if ($this->gameSessionId === null) {
            return;
        }

        $participant = GameParticipant::query()
            ->where('game_session_id', $this->gameSessionId)
            ->where('user_id', Auth::id())
            ->first();

        if ($participant !== null) {
            $this->score = $participant->score;
        }
    }

    private function finishGame(Solver $solver, SharedSessionFinalizer $finalizer): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;
        $this->path = [];
        $this->allValidWords = $solver->solve($this->board);

        // "End Game" clicked to stop a *timed* solo game early, before its
        // own clock actually reached zero -- as opposed to either a timed
        // game whose clock genuinely ran out (the JS countdown only ever
        // calls this once $this->remainingSeconds has already reached 0,
        // so the server sees the same 0 by the time this runs) or an
        // untimed game, whose *only* real finish condition is clicking
        // this same button (remainingSeconds is null there, never > 0).
        // Neither of those two legitimate-finish cases should be treated
        // as "not finished" just because the trigger was the same button
        // click -- only a genuine early stop should skip recording.
        // Deliberately solo-only: an online room's End Game ends the
        // shared session for every seat at once, a materially bigger
        // consequence than one player quitting their own local timer
        // early, and out of scope for this specific fix.
        if (! $this->isSharedSession && ($this->remainingSeconds ?? 0) > 0) {
            $this->endedEarly = true;

            return;
        }

        if ($this->isSharedSession) {
            $session = GameSession::find($this->gameSessionId);
            $session->update(['ended_at' => now()]);

            // Room only, not an async $challenge -- see docs/DEVLOG.md's
            // Farkle "Back to room" fix. Without this, Room.status/
            // game_session_id stay exactly as they were when the game
            // started, and pages::boggle.room's own
            // redirectIfAlreadyStarted() bounces "Back to room" straight
            // back into this now-finished game instead of the lobby.
            if ($this->room !== null) {
                Room::where('code', $this->room)->update(['status' => RoomStatus::Finished]);
            }
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

        $stats = [
            'words_found' => count($this->foundWords),
            'found_words' => $this->foundWords,
            // The board's full solved word list, already computed above
            // to render the reveal screen -- persisting just the count
            // is what lets the profile's Boggle stats show "X% of the
            // board found" without re-running the Solver (an ~80k-word
            // dictionary build) for every historical game on every view.
            'total_valid_words' => count($this->allValidWords),
        ];

        // updateOrCreate (not create) for an authenticated user -- mount()
        // above now restores $ended before this can normally run twice,
        // but this is the actual data-integrity guarantee: even if it did
        // run twice (a race between two tabs, say), the same account can
        // never end up with two GameParticipant rows for the same session.
        // Guests skip this: every guest in a session shares user_id ===
        // null, so there's no stable key to updateOrCreate on without
        // risking overwriting a *different* guest's row.
        $participant = Auth::check()
            ? GameParticipant::updateOrCreate(
                ['game_session_id' => $session->id, 'user_id' => Auth::id()],
                ['guest_name' => null, 'score' => $this->score, 'stats' => $stats],
            )
            : GameParticipant::create([
                'game_session_id' => $session->id,
                'user_id' => null,
                'guest_name' => 'Guest',
                'score' => $this->score,
                'stats' => $stats,
            ]);

        if ($this->isSharedSession) {
            $finalizer->finalize($session);
            // Re-read rather than trust the in-memory $participant -- its
            // score was just overwritten (to the "only words nobody else
            // also found" value) by finalizeSharedSession(), and the HUD/
            // reveal screen should show that adjusted score, not the raw
            // as-you-played one.
            $this->score = $participant->fresh()->score;
        }

        // Online rooms only -- an async challenge link has no "everyone
        // playing at once" to keep in sync; each side plays it on their own
        // schedule, sometimes days apart, so there's nothing to broadcast.
        // Always broadcast here, even when this finish was itself triggered
        // by a cascade (see onRoomGameEnded()) -- an earlier finisher's own
        // score may have just changed (finalizeSharedSession() above just
        // recalculated everyone using this now-larger participant set), and
        // this is what tells their browser to refresh it.
        if ($this->room !== null) {
            RoomGameEnded::dispatch($this->room);
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
        $this->endedEarly = false;
        $this->allValidWords = null;
        $this->message = null;
        // Previously always overwritten by finishGame()'s own
        // GameSession::create() before ever being read, so leaving it
        // stale here was harmless -- no longer true now that finishGame()
        // can set $ended without touching this (an early-ended game, see
        // its own docblock), which would otherwise leak the *previous*
        // game's share link onto this one's reveal screen.
        $this->gameSessionId = null;
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
            ->orderBy('id')
            ->get();
    }

    /**
     * word => list of finder tags ('me', 'other-0', 'other-1', ...), for a
     * shared session's post-game reveal. The Blade template reads this
     * once into a local variable and passes each word's own entry to
     * wordClass() below, rather than calling this (and its
     * otherParticipants query) once per word in the reveal list.
     *
     * @return array<string, list<string>>
     */
    public function getWordFindersProperty(): array
    {
        $finders = [];

        foreach (array_keys($this->foundWords) as $word) {
            $finders[$word][] = 'me';
        }

        foreach ($this->otherParticipants as $index => $participant) {
            foreach (array_keys($participant->stats['found_words'] ?? []) as $word) {
                $finders[$word][] = 'other-'.$index;
            }
        }

        return $finders;
    }

    /**
     * The CSS class for one word in the reveal list -- pass
     * `$wordFinders[$word] ?? []` (see getWordFindersProperty()), not the
     * word itself.
     *
     * @param  list<string>  $finders
     */
    public function wordClass(array $finders): string
    {
        if (! $this->isSharedSession) {
            return $finders !== [] ? 'found' : '';
        }

        if ($finders === []) {
            return '';
        }

        if (count($finders) > 1) {
            return 'found-shared';
        }

        if ($finders[0] === 'me') {
            return 'found-me';
        }

        $otherIndex = (int) str_replace('other-', '', $finders[0]);

        return 'found-other-'.($otherIndex % 4);
    }

    /**
     * @return list<array{label: string, score: int, isMe: bool}>
     */
    public function getScoreboardProperty(): array
    {
        if (! $this->isSharedSession || ! $this->ended) {
            return [];
        }

        $entries = [['label' => 'You', 'score' => $this->score, 'isMe' => true]];

        foreach ($this->otherParticipants as $participant) {
            $entries[] = [
                'label' => $participant->user->name ?? $participant->guest_name ?? 'Player',
                'score' => $participant->score,
                'isMe' => false,
            ];
        }

        return $entries;
    }

    public function getWinnerLabelProperty(): ?string
    {
        $board = $this->scoreboard;

        if (count($board) < 2) {
            return null;
        }

        $topScore = max(array_column($board, 'score'));
        $winners = array_values(array_filter($board, fn (array $entry) => $entry['score'] === $topScore));

        if (count($winners) > 1) {
            return "It's a tie at {$topScore}!";
        }

        return $winners[0]['isMe']
            ? "You win with {$topScore}!"
            : "{$winners[0]['label']} wins with {$topScore}!";
    }

    public function getRemainingSecondsProperty(): ?int
    {
        if ($this->endsAt === null) {
            return null;
        }

        return max(0, $this->endsAt - now()->timestamp);
    }

    public function getTimerDisplayProperty(): string
    {
        $remaining = $this->remainingSeconds;

        if ($remaining === null) {
            return '∞';
        }

        return sprintf('%d:%02d', intdiv($remaining, 60), $remaining % 60);
    }

    public function getTimerLowProperty(): bool
    {
        return $this->remainingSeconds !== null && $this->remainingSeconds <= 30;
    }

    /**
     * All valid words grouped by length, ascending -- used by the
     * post-game reveal panel.
     *
     * @return array<int, list<string>>
     */
    public function getWordsByLengthProperty(): array
    {
        $groups = [];

        foreach ($this->allValidWords ?? [] as $word) {
            $groups[mb_strlen($word)][] = $word;
        }

        ksort($groups);

        return $groups;
    }

    public function letterClass(string $display): string
    {
        $classes = ['letter'];

        if (in_array($display, self::WIDE, true)) {
            $classes[] = 'wide';
        }

        if (in_array($display, self::DESCENDER, true)) {
            $classes[] = 'descender';
        }

        return implode(' ', $classes);
    }
};
?>

<x-slot:title>Word Grid — Low Stim Play</x-slot>
<x-slot:description>Play Word Grid online — a shuffled 4x4 or 5x5 letter grid, timed or untimed, with calm low-contrast visuals and no flashing or motion.</x-slot>
<x-slot:bodyClass>boggle-play</x-slot:bodyClass>
<x-slot:fullscreenToggle>1</x-slot:fullscreenToggle>
<x-slot:rulesTitle>Word Grid Rules</x-slot:rulesTitle>
<x-slot:rules>
    <h3>How to Use This Page</h3>
    <ul>
        <li>Choose a board size and time limit (or switch to Untimed), then press Start Game.</li>
        <li>Drag across adjacent letters, or tap them one at a time, to spell a word.</li>
        <li>Tap the last letter again, or press Enter, to submit the word. Press Escape or Backspace to clear or undo your selection.</li>
        <li>The HUD shows time remaining (or &infin; if untimed), your score, and words found.</li>
        <li>Press End Game any time to stop early &mdash; a timed game also ends on its own when the clock runs out.</li>
        <li>When the game ends, every word the board could have made is revealed, with the ones you found marked.</li>
    </ul>

    <h3>Full Rules</h3>
    <ul>
        <li>The board is a grid of lettered dice (4&times;4 "Classic" or 5&times;5 "Big"), freshly shuffled every game.</li>
        <li>Build a word by chaining letters that are adjacent to each other, including diagonally. Each die can only be used once per word.</li>
        <li>Words must be at least 3 letters long. "Qu" on a die counts as one letter, standing in for both Q and U (e.g. "Qu" + "I" + "T" spells QUIT).</li>
        <li>Only real dictionary words count, checked against the game's built-in word list. Each word only scores once per game.</li>
        <li>Scoring is by word length: 3&ndash;4 letters = 1 point, 5 = 2, 6 = 3, 7 = 5, 8 or more = 11 points.</li>
    </ul>
</x-slot:rules>
<div class="flex flex-col items-center w-full">
    {{--
        Teleported (see layouts/app.blade.php's #game-actions anchor) rather
        than a layout $barRight slot -- this button's label depends on
        $ended, which changes after the page's first render, and a layout
        slot only ever captures its content once, at that first render.
    --}}
    @teleport('#game-actions')
        @unless ($ended)
            <button type="button" id="end-game-btn" wire:click="endGame">End Game</button>
        @else
            <button type="button" wire:click="resetGame" @if($isSharedSession) disabled @endif>New Game</button>
        @endunless
    @endteleport

    <div id="game" class="{{ $ended ? 'with-list' : '' }}">
        <div class="hud">
            <div class="hud-item">
                <div class="hud-label">TIME</div>
                <div class="hud-value timer {{ $this->timerLow ? 'low' : '' }}" id="timer-value">{{ $this->timerDisplay }}</div>
            </div>
            <div class="hud-item">
                <div class="hud-label">SCORE</div>
                <div class="hud-value">{{ $score }}</div>
            </div>
            <div class="hud-item">
                <div class="hud-label">WORDS</div>
                <div class="hud-value">{{ $ended ? count($foundWords).' / '.count($allValidWords ?? []) : count($foundWords) }}</div>
            </div>
        </div>

        <div class="board-area" id="board-area">
            <div class="grid-stack">
                <div class="grid {{ $ended ? 'inactive' : '' }}" id="grid"
                    style="grid-template-columns: repeat({{ $size }}, minmax(0, 1fr));">
                    @foreach ($board['cells'] as $cell)
                        <div class="die" data-index="{{ $cell['index'] }}" wire:key="die-{{ $cell['index'] }}">
                            <span class="{{ $this->letterClass($cell['display']) }}">{{ $cell['display'] }}</span>
                            <span class="order-badge"></span>
                        </div>
                    @endforeach
                </div>
                <div class="status-line" id="status-line">{{ $message ?? 'Drag or tap adjacent letters to build a word.' }}</div>
            </div>

            <div class="word-list-panel" id="word-list-panel">
                @unless ($ended)
                    <div class="title" id="word-list-title">Words Found</div>
                    <ul class="word-list" id="found-list">
                        @foreach ($foundWords as $word => $points)
                            <li wire:key="found-{{ $word }}">{{ strtoupper($word) }} ({{ $points }})</li>
                        @endforeach
                    </ul>
                @else
                    @php $wordFinders = $this->wordFinders; @endphp
                    <div class="title" id="word-list-title">All Possible Words ({{ count($allValidWords ?? []) }})</div>
                    @if ($isSharedSession)
                        <div class="word-legend">
                            <span class="word-legend-item">
                                <span class="word-legend-swatch" style="background: var(--accent);"></span> You
                            </span>
                            @foreach ($this->otherParticipants as $index => $participant)
                                <span class="word-legend-item">
                                    <span class="word-legend-swatch" style="background: var(--rival-{{ ($index % 4) + 1 }});"></span>
                                    {{ $participant->user->name ?? $participant->guest_name ?? 'Player' }}
                                </span>
                            @endforeach
                            <span class="word-legend-item">
                                <span class="word-legend-swatch" style="background: var(--bar);"></span> Both/All
                            </span>
                        </div>
                    @endif
                    <div class="word-columns" id="word-columns">
                        @foreach ($this->wordsByLength as $length => $words)
                            <div class="word-column">
                                <div class="word-column-header">{{ $length }} letters</div>
                                <ul class="word-list">
                                    @foreach ($words as $word)
                                        <li wire:key="reveal-{{ $word }}" class="{{ $this->wordClass($wordFinders[$word] ?? []) }}">
                                            {{ strtoupper($word) }}
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                @endunless
            </div>
        </div>
    </div>

    @if ($ended)
        <div class="flex flex-col items-center gap-4 w-full max-w-md" style="margin-top: clamp(12px, 2.2vmin, 26px);">
            @if ($endedEarly)
                <p class="status-line">Ended early with time still on the clock -- not saved to your stats.</p>
            @endif

            @if ($isSharedSession)
                <div class="flex flex-col items-center gap-1 w-full">
                    @foreach ($this->scoreboard as $entry)
                        <p class="status-line" style="{{ $entry['isMe'] ? 'color: var(--accent); font-weight: bold;' : '' }}">
                            {{ $entry['isMe'] ? 'You' : $entry['label'] }}: {{ $entry['score'] }}
                        </p>
                    @endforeach

                    @if ($this->winnerLabel)
                        <p class="status" style="margin-top: 4px;">{{ $this->winnerLabel }}</p>
                    @else
                        <p class="status-line">Waiting for the other player to finish.</p>
                    @endif
                </div>
            @endif

            @if ($room !== null)
                <a href="{{ route('room.show', ['code' => $room]) }}"><button type="button">Back to room</button></a>
            @elseif ($challenge !== null)
                <a href="{{ route('boggle.lobby') }}"><button type="button">Back home</button></a>
            @elseif ($gameSessionId !== null)
                <div class="flex gap-2 w-full">
                    <input type="text" readonly value="{{ route('boggle.share', ['gameSession' => $gameSessionId]) }}"
                        onclick="this.select()" style="flex: 1;">
                    <button type="button" data-copy="{{ route('boggle.share', ['gameSession' => $gameSessionId]) }}">
                        Copy
                    </button>
                </div>
            @endif
        </div>
    @endif
</div>

@script
<script>
    const gridEl = document.getElementById('grid');
    const statusLineEl = document.getElementById('status-line');

    // body's own reactively-toggled classes (rather than server-rendered,
    // since a Livewire round trip only re-renders this component's own
    // root element -- <body>, sitting outside it in the surrounding
    // layout, never gets touched by that diff) -- matches legacy/
    // boggle.html's startGame()/endGame() toggling document.body's
    // classList directly instead.
    document.body.classList.toggle('in-game', !$wire.ended);
    $wire.$watch('ended', (ended) => {
        document.body.classList.toggle('in-game', !ended);
    });

    // Countdown runs entirely client-side (matching legacy/boggle.html's
    // own setInterval), rather than a wire:poll round trip every second --
    // a periodic full-component re-render while a drag might be mid-flight
    // would let Livewire's morph step clobber the .selected/order-badge
    // state that JS alone is managing between submits. endGame() is only
    // ever called once, right when time actually runs out.
    const timerValueEl = document.getElementById('timer-value');
    let endsAt = $wire.endsAt;
    let timerInterval = null;

    function updateTimerDisplay() {
        if (endsAt === null) {
            timerValueEl.textContent = '∞';
            timerValueEl.classList.remove('low');
            return;
        }
        const remaining = Math.max(0, Math.round(endsAt - Date.now() / 1000));
        const m = Math.floor(remaining / 60);
        const s = remaining % 60;
        timerValueEl.textContent = m + ':' + String(s).padStart(2, '0');
        timerValueEl.classList.toggle('low', remaining <= 30);
        if (remaining <= 0 && !$wire.ended) {
            clearInterval(timerInterval);
            $wire.endGame();
        }
    }

    function startTimerLoop() {
        clearInterval(timerInterval);
        updateTimerDisplay();
        if (endsAt !== null) {
            timerInterval = setInterval(updateTimerDisplay, 1000);
        }
    }

    startTimerLoop();

    $wire.$watch('endsAt', (value) => {
        endsAt = value;
        startTimerLoop();
    });

    $wire.$watch('ended', (ended) => {
        if (ended) {
            clearInterval(timerInterval);
        }
    });

    let path = [];
    let pointerActive = false;
    let dragMoved = false;
    let tapOnTail = false;
    let lastRawPos = null;
    let dieCenters = [];
    let dieHitRadius = 0;

    // Discounts a diagonal candidate's effective distance so a hand-drawn
    // diagonal drag (never a perfect 45° line) still resolves to the
    // diagonal die rather than whichever orthogonal neighbor the pointer
    // strayed closer to -- see legacy/boggle.html's nearestCandidateCell
    // for the full reasoning.
    const DIAGONAL_BIAS = 0.65;

    function cacheDieCenters() {
        dieCenters = [];
        let size = 0;
        gridEl.querySelectorAll('.die').forEach((dieEl) => {
            const idx = Number(dieEl.dataset.index);
            const r = dieEl.getBoundingClientRect();
            dieCenters[idx] = { x: r.left + r.width / 2, y: r.top + r.height / 2 };
            size = r.width;
        });
        dieHitRadius = size * 1.6;
    }

    function renderPathHighlight() {
        gridEl.querySelectorAll('.die').forEach((dieEl) => {
            const index = Number(dieEl.dataset.index);
            const pos = path.indexOf(index);
            dieEl.classList.toggle('selected', pos !== -1);
            const badge = dieEl.querySelector('.order-badge');
            if (badge) {
                badge.textContent = pos !== -1 ? String(pos + 1) : '';
            }
        });
    }

    function resetSelection() {
        path = [];
        renderPathHighlight();
    }

    function stepPath(index, allowRestart) {
        const neighborsOf = $wire.board.neighbors;
        const posInPath = path.indexOf(index);
        if (posInPath !== -1) {
            if (posInPath === path.length - 1) {
                return false;
            }
            path = path.slice(0, posInPath + 1);
            return true;
        }
        if (path.length === 0) {
            path.push(index);
            return true;
        }
        const last = path[path.length - 1];
        if (neighborsOf[last].includes(index)) {
            path.push(index);
            return true;
        }
        if (allowRestart) {
            path = [index];
            return true;
        }
        return false;
    }

    function nearestCandidateCell(x, y) {
        if (path.length === 0) {
            return null;
        }
        const neighborsOf = $wire.board.neighbors;
        const cells = $wire.board.cells;
        const lastIndex = path[path.length - 1];
        const lastCell = cells[lastIndex];
        const candidates = new Set([lastIndex, ...neighborsOf[lastIndex], ...path]);
        let best = null;
        let bestScore = Infinity;
        candidates.forEach((i) => {
            const c = dieCenters[i];
            if (!c) {
                return;
            }
            let dist = Math.hypot(c.x - x, c.y - y);
            const cell = cells[i];
            const isDiagonal = Math.abs(cell.row - lastCell.row) === 1 && Math.abs(cell.col - lastCell.col) === 1;
            if (isDiagonal) {
                dist *= DIAGONAL_BIAS;
            }
            if (dist < bestScore) {
                bestScore = dist;
                best = i;
            }
        });
        if (best === null || bestScore > dieHitRadius) {
            return null;
        }
        return best;
    }

    function processDragPoint(x, y) {
        const index = nearestCandidateCell(x, y);
        if (index === null) {
            return;
        }
        if (path.length && index === path[path.length - 1]) {
            return;
        }
        if (stepPath(index, false)) {
            dragMoved = true;
            renderPathHighlight();
        }
    }

    function submitCurrentPath() {
        const submittedPath = path;
        resetSelection();
        $wire.submitPath(submittedPath).then(() => {
            statusLineEl.textContent = $wire.message || 'Drag or tap adjacent letters to build a word.';
        });
    }

    gridEl.addEventListener('pointerdown', (e) => {
        if ($wire.ended) {
            return;
        }
        const dieEl = e.target.closest('.die');
        if (!dieEl) {
            return;
        }
        pointerActive = true;
        dragMoved = false;
        cacheDieCenters();
        gridEl.setPointerCapture(e.pointerId);
        const index = Number(dieEl.dataset.index);
        tapOnTail = path.length > 0 && path[path.length - 1] === index;
        if (!tapOnTail) {
            stepPath(index, true);
            renderPathHighlight();
        }
        lastRawPos = { x: e.clientX, y: e.clientY };
    });

    gridEl.addEventListener('pointermove', (e) => {
        if ($wire.ended || !pointerActive) {
            return;
        }
        const to = { x: e.clientX, y: e.clientY };
        const from = lastRawPos || to;
        const dist = Math.hypot(to.x - from.x, to.y - from.y);
        const steps = Math.max(1, Math.ceil(dist / (dieHitRadius / 3 || 20)));
        for (let s = 1; s <= steps; s++) {
            const t = s / steps;
            processDragPoint(from.x + (to.x - from.x) * t, from.y + (to.y - from.y) * t);
        }
        lastRawPos = to;
    });

    function endPointerInteraction() {
        if (!pointerActive) {
            return;
        }
        pointerActive = false;
        lastRawPos = null;
        if (!$wire.ended && (dragMoved || tapOnTail)) {
            submitCurrentPath();
        }
        dragMoved = false;
        tapOnTail = false;
    }

    gridEl.addEventListener('pointerup', endPointerInteraction);
    gridEl.addEventListener('pointercancel', endPointerInteraction);

    document.addEventListener('keydown', (e) => {
        if ($wire.ended) {
            return;
        }
        if (e.key === 'Enter') {
            if (path.length) {
                submitCurrentPath();
            }
        } else if (e.key === 'Escape') {
            resetSelection();
            statusLineEl.textContent = 'Drag or tap adjacent letters to build a word.';
        } else if (e.key === 'Backspace') {
            path.pop();
            renderPathHighlight();
        }
    });
</script>
@endscript
