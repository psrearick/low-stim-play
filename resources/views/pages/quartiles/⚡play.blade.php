<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Services\Boggle\Solver;
use App\Services\Puzzles\DailyPuzzle;
use App\Services\Quartiles\PuzzleGenerator;
use App\Services\Quartiles\Scorer;
use App\Services\Quartiles\WordFinder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    private const MAX_SELECTION = 4;

    private const GROUP_COUNT = 5;

    /**
     * Sharing a seed makes an identical puzzle reproducible -- same
     * purpose as Boggle's own `#[Url] $seed`, unused by any UI yet but
     * free forward-compatibility for Task 11's future "puzzle codes" work
     * (see docs/specs/quartiles.md section 4). An explicit seed always
     * wins over resuming a saved in-progress game (section 4a) -- it's a
     * deliberate "play this specific puzzle" request. Ignored by
     * resetGame() ("New Game" always generates a genuinely fresh puzzle).
     */
    #[Url]
    public ?int $seed = null;

    /**
     * A `Y-m-d` date string -- when set, the puzzle is that day's daily
     * (seed derived by DailyPuzzle::seedFor(), not $seed above), not an
     * arbitrary shared puzzle. Only *today's* date is locked to one
     * signed-in play per day (see mountDaily()); any other date -- an
     * archive entry, or any date at all for a guest -- is just an
     * ordinary seeded puzzle, freely replayable.
     */
    #[Url]
    public ?string $dailyDate = null;

    /**
     * True once mountDaily() has restored *today's* already-finished
     * attempt -- distinct from $ended (true for every ended game) so the
     * reveal screen can show a "come back tomorrow" countdown instead of
     * the ordinary share-link box, which wouldn't mean anything for a
     * fixed daily puzzle.
     */
    public bool $dailyAlreadyDone = false;

    /**
     * id => {display, match, groupIndex} -- static for the whole game once
     * generated, never mutated after mount()/resetGame().
     *
     * @var array<int, array{display: string, match: string, groupIndex: int}>
     */
    public array $tiles = [];

    /**
     * groupIndex => list of the 4 tile ids that make up that quartile.
     * Static, same lifetime as $tiles.
     *
     * @var list<list<int>>
     */
    public array $groups = [];

    /**
     * Tile ids not yet part of a found quartile, in their current grid
     * (shuffle) order. Shrinks by 4 every time a quartile is found; the
     * remainder is reshuffled at that point and by shuffleTiles() below.
     *
     * @var list<int>
     */
    public array $unfoundOrder = [];

    /**
     * Indices into $groups, in the order each quartile was actually found
     * -- also what drives rendering each found quartile's own fixed row at
     * the top of the grid (see docs/specs/quartiles.md section 3).
     *
     * @var list<int>
     */
    public array $foundGroups = [];

    /**
     * Tile ids selected so far, in click order -- concatenating their
     * `match` text in this order is the candidate word submitWord() below
     * checks.
     *
     * @var list<int>
     */
    public array $selected = [];

    /**
     * Found words and the points they were worth, keyed by word -- same
     * shape as Boggle's own $foundWords.
     *
     * @var array<string, int>
     */
    public array $foundWords = [];

    public int $score = 0;

    /**
     * The seed the current puzzle was actually generated with (whether
     * from $seed above or freshly randomized) -- distinct from that
     * property so an unspecified $seed (the ordinary case) doesn't force
     * it to stay null; persisted into GameSession.settings at endGame().
     */
    public int $resolvedSeed = 0;

    public int $startedAt;

    public bool $ended = false;

    /**
     * Every valid word the puzzle contains, word => max tile-count it can
     * be formed with. Null until either all 5 quartiles are found (so the
     * "keep going" banner can report how many words remain) or the game
     * ends -- computed at most once per game (WordFinder::findAll() is not
     * cheap enough to run on every render), never recomputed afterward.
     *
     * @var array<string, int>|null
     */
    public ?array $allValidWords = null;

    public ?string $message = null;

    public ?int $gameSessionId = null;

    public function mount(PuzzleGenerator $generator, DailyPuzzle $dailyPuzzle, WordFinder $wordFinder): void
    {
        if ($this->dailyDate !== null) {
            $this->mountDaily($generator, $dailyPuzzle, $wordFinder);

            return;
        }

        if ($this->seed === null && Auth::check() && $this->resumeInProgressGame()) {
            return;
        }

        $this->loadNewPuzzle($generator, $this->seed);
        $this->startedAt = now()->timestamp;
        $this->startNewSession();
    }

    public function toggleTile(int $tileId): void
    {
        if ($this->ended || ! isset($this->tiles[$tileId])) {
            return;
        }

        $index = array_search($tileId, $this->selected, true);

        if ($index !== false) {
            array_splice($this->selected, $index, 1);

            return;
        }

        if (count($this->selected) >= self::MAX_SELECTION) {
            return;
        }

        $this->selected[] = $tileId;
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function submitWord(Solver $solver, Scorer $scorer, WordFinder $wordFinder): void
    {
        if ($this->ended || $this->selected === []) {
            return;
        }

        $tileIds = $this->selected;
        $word = implode('', array_map(fn (int $id) => $this->tiles[$id]['match'], $tileIds));

        $this->selected = [];

        if (isset($this->foundWords[$word])) {
            $this->message = 'Already found.';

            return;
        }

        if (! $solver->isValidWord($word)) {
            $this->message = 'Not a word.';

            return;
        }

        $points = $scorer->pointsFor(count($tileIds));
        $this->foundWords[$word] = $points;
        $this->score += $points;
        $this->message = null;

        $this->checkForQuartile($tileIds, $wordFinder);
        $this->persistState();
    }

    public function shuffleTiles(): void
    {
        if ($this->ended) {
            return;
        }

        $this->unfoundOrder = collect($this->unfoundOrder)->shuffle()->values()->all();
        $this->persistState();
    }

    public function endGame(WordFinder $wordFinder): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;
        $this->selected = [];
        $this->resolveAllValidWords($wordFinder);

        $stats = [
            'found_words' => $this->foundWords,
            'words_found' => count($this->foundWords),
            'quartiles_found' => count($this->foundGroups),
            'total_valid_words' => count($this->allValidWords),
        ];

        // A signed-in user's session already exists (created at mount/New
        // Game time -- see startNewSession()) -- update it in
        // place rather than creating a second row. Guests were never given
        // an early row (see docs/specs/quartiles.md section 4a: resuming
        // is signed-in-only), so they still get one created here exactly
        // as v1 did.
        if (Auth::check() && $this->gameSessionId !== null) {
            $session = GameSession::find($this->gameSessionId);
            $session->update(['state' => $this->progressState(), 'ended_at' => now()]);
        } else {
            $session = GameSession::create([
                'type' => GameType::Quartiles,
                'mode' => GameMode::Solo,
                'settings' => ['seed' => $this->resolvedSeed],
                'started_at' => now()->setTimestamp($this->startedAt),
                'ended_at' => now(),
            ]);
            $this->gameSessionId = $session->id;
        }

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
    }

    public function resetGame(PuzzleGenerator $generator): void
    {
        $this->loadNewPuzzle($generator);
        $this->resetProgress();
        $this->startNewSession();
    }

    public function getSelectedWordProperty(): string
    {
        return strtoupper(implode('', array_map(
            fn (int $id) => $this->tiles[$id]['match'] ?? '',
            $this->selected,
        )));
    }

    /**
     * $foundWords, alphabetically -- insertion (find) order isn't a useful
     * reading order once there are more than a couple of entries.
     *
     * @return array<string, int>
     */
    public function getFoundWordsSortedProperty(): array
    {
        $sorted = $this->foundWords;
        ksort($sorted);

        return $sorted;
    }

    /**
     * Each quartile's answer word alongside whether it's been found yet --
     * used both for the live "N/5 quartiles found" progress readout and
     * the post-game reveal.
     *
     * @return list<array{word: string, found: bool}>
     */
    public function getQuartileAnswersProperty(): array
    {
        return array_map(
            fn (array $tileIds, int $groupIndex) => [
                'word' => implode('', array_map(fn (int $id) => $this->tiles[$id]['match'], $tileIds)),
                'found' => in_array($groupIndex, $this->foundGroups, true),
            ],
            $this->groups,
            array_keys($this->groups),
        );
    }

    /**
     * All valid words grouped by the tile-count they can be formed with,
     * ascending -- the post-game reveal panel's own grouping, matching
     * this game's tile-count-based scoring rather than Boggle's
     * letter-length grouping.
     *
     * @return array<int, list<string>>
     */
    public function getRevealByTileCountProperty(): array
    {
        $groups = [];

        foreach ($this->allValidWords ?? [] as $word => $tileCount) {
            $groups[$tileCount][] = $word;
        }

        foreach (array_keys($groups) as $tileCount) {
            sort($groups[$tileCount]);
        }

        ksort($groups);

        return $groups;
    }

    /**
     * Null once the game has ended (the reveal panel takes over) or before
     * all 5 quartiles are found. In between, tells the player there's more
     * to find without forcing them to keep playing -- see
     * docs/specs/quartiles.md section 3.
     */
    public function getBannerProperty(): ?string
    {
        if ($this->ended || count($this->foundGroups) < self::GROUP_COUNT) {
            return null;
        }

        $remaining = $this->allValidWords !== null
            ? count($this->allValidWords) - count($this->foundWords)
            : 0;

        if ($remaining <= 0) {
            return 'All 5 Quads found — you found everything!';
        }

        $word = $remaining === 1 ? 'word' : 'words';

        return "All 5 Quads found! {$remaining} more {$word} to find, or press End Game whenever you're ready.";
    }

    /**
     * @param  list<int>  $tileIds
     */
    private function checkForQuartile(array $tileIds, WordFinder $wordFinder): void
    {
        if (count($tileIds) !== self::MAX_SELECTION) {
            return;
        }

        $sortedSelected = collect($tileIds)->sort()->values()->all();

        foreach ($this->groups as $groupIndex => $groupTileIds) {
            if (in_array($groupIndex, $this->foundGroups, true)) {
                continue;
            }

            if ($sortedSelected !== collect($groupTileIds)->sort()->values()->all()) {
                continue;
            }

            $this->foundGroups[] = $groupIndex;
            $this->unfoundOrder = collect($this->unfoundOrder)
                ->reject(fn (int $id) => in_array($id, $groupTileIds, true))
                ->shuffle()
                ->values()
                ->all();

            if (count($this->foundGroups) === self::GROUP_COUNT) {
                $this->resolveAllValidWords($wordFinder);
            }

            return;
        }
    }

    private function resolveAllValidWords(WordFinder $wordFinder): void
    {
        if ($this->allValidWords !== null) {
            return;
        }

        $tileTexts = array_map(fn (array $tile) => $tile['match'], $this->tiles);
        $this->allValidWords = $wordFinder->findAll($tileTexts);
    }

    private function loadNewPuzzle(PuzzleGenerator $generator, ?int $seed = null): void
    {
        $puzzle = $generator->generate($seed);
        $this->tiles = $puzzle['tiles'];
        $this->groups = $puzzle['groups'];
        $this->unfoundOrder = $puzzle['tileOrder'];
        $this->resolvedSeed = $puzzle['seed'];
    }

    /**
     * Resets every progress field back to a fresh-game state, leaving
     * $tiles/$groups untouched -- called by resetGame() after it generates
     * a new puzzle.
     */
    private function resetProgress(): void
    {
        $this->foundGroups = [];
        $this->selected = [];
        $this->foundWords = [];
        $this->score = 0;
        $this->ended = false;
        $this->allValidWords = null;
        $this->message = null;
        $this->startedAt = now()->timestamp;
        $this->gameSessionId = null;
    }

    /**
     * @return array{tiles: array<int, array{display: string, match: string, groupIndex: int}>, groups: list<list<int>>, unfoundOrder: list<int>, foundGroups: list<int>, foundWords: array<string, int>, score: int}
     */
    private function progressState(): array
    {
        return [
            'tiles' => $this->tiles,
            'groups' => $this->groups,
            'unfoundOrder' => $this->unfoundOrder,
            'foundGroups' => $this->foundGroups,
            'foundWords' => $this->foundWords,
            'score' => $this->score,
        ];
    }

    /**
     * Hydrates $this from a signed-in user's own saved in-progress
     * GameSession, if one exists -- see docs/specs/quartiles.md section 4a.
     * $selected is deliberately left empty (never persisted). Scoped to
     * `is_daily = false` -- a daily attempt gets its own separate
     * in-progress row (see mountDaily()/findDailySession()), so a signed-in
     * user can have one of each in progress at once without either
     * clobbering the other.
     */
    private function resumeInProgressGame(): bool
    {
        $session = GameSession::query()
            ->where('user_id', Auth::id())
            ->where('type', GameType::Quartiles)
            ->where('is_daily', false)
            ->whereNull('ended_at')
            ->first();

        if ($session === null) {
            return false;
        }

        $state = $session->state ?? [];

        $this->tiles = $state['tiles'] ?? [];
        $this->groups = $state['groups'] ?? [];
        $this->unfoundOrder = $state['unfoundOrder'] ?? [];
        $this->foundGroups = $state['foundGroups'] ?? [];
        $this->foundWords = $state['foundWords'] ?? [];
        $this->score = $state['score'] ?? 0;
        $this->resolvedSeed = $session->settings['seed'] ?? 0;
        $this->startedAt = $session->started_at->timestamp;
        $this->gameSessionId = $session->id;

        return true;
    }

    /**
     * Starts tracking the current puzzle as this signed-in user's
     * in-progress game -- reuses whatever unfinished row already exists
     * for them (abandoning its puzzle in favor of this one) rather than
     * accumulating a second row, but never touches an already-*finished*
     * row (that would silently rewrite another game's own recorded
     * history -- see docs/specs/quartiles.md section 4a). A no-op for
     * guests, who are never given an early row at all.
     */
    private function startNewSession(): void
    {
        if (! Auth::check()) {
            return;
        }

        $attributes = [
            'user_id' => Auth::id(),
            'type' => GameType::Quartiles,
            'mode' => GameMode::Solo,
            'is_daily' => false,
            'settings' => ['seed' => $this->resolvedSeed],
            'state' => $this->progressState(),
            'started_at' => now()->setTimestamp($this->startedAt),
            'ended_at' => null,
        ];

        $existing = GameSession::query()
            ->where('user_id', Auth::id())
            ->where('type', GameType::Quartiles)
            ->where('is_daily', false)
            ->whereNull('ended_at')
            ->first();

        if ($existing !== null) {
            $existing->update($attributes);
            $this->gameSessionId = $existing->id;

            return;
        }

        $this->gameSessionId = GameSession::create($attributes)->id;
    }

    /**
     * Mounts a daily puzzle -- a deterministic seed derived from
     * $dailyDate via DailyPuzzle::seedFor(), not an arbitrary $seed
     * override. Only *today's* date is locked to one signed-in play per
     * day (resumable mid-attempt, restored read-only once finished); any
     * other date -- an archive entry, or any date at all for a guest -- is
     * just an ordinary seeded puzzle with no early row and no lock, freely
     * replayable.
     */
    private function mountDaily(PuzzleGenerator $generator, DailyPuzzle $dailyPuzzle, WordFinder $wordFinder): void
    {
        $date = Carbon::parse($this->dailyDate)->startOfDay();
        $seed = $dailyPuzzle->seedFor(GameType::Quartiles, $date);

        if ($date->isToday() && Auth::check()) {
            $session = $this->findDailySession($date);

            if ($session !== null) {
                $this->restoreDailySession($session, $wordFinder);

                return;
            }

            $this->loadNewPuzzle($generator, $seed);
            $this->startedAt = now()->timestamp;
            $this->startDailySession($date);

            return;
        }

        $this->loadNewPuzzle($generator, $seed);
        $this->startedAt = now()->timestamp;
    }

    private function findDailySession(Carbon $date): ?GameSession
    {
        return GameSession::query()
            ->where('user_id', Auth::id())
            ->where('type', GameType::Quartiles)
            ->where('is_daily', true)
            ->where('daily_date', $date->toDateString())
            ->first();
    }

    /**
     * Hydrates $this from today's daily GameSession -- in progress or
     * already finished. A finished row is restored read-only ($ended and
     * $dailyAlreadyDone both true, no puzzle regenerated), same shape
     * Boggle's own isSharedSession restore uses.
     */
    private function restoreDailySession(GameSession $session, WordFinder $wordFinder): void
    {
        $state = $session->state ?? [];

        $this->tiles = $state['tiles'] ?? [];
        $this->groups = $state['groups'] ?? [];
        $this->unfoundOrder = $state['unfoundOrder'] ?? [];
        $this->foundGroups = $state['foundGroups'] ?? [];
        $this->foundWords = $state['foundWords'] ?? [];
        $this->score = $state['score'] ?? 0;
        $this->resolvedSeed = $session->settings['seed'] ?? 0;
        $this->startedAt = $session->started_at->timestamp;
        $this->gameSessionId = $session->id;

        if ($session->ended_at === null) {
            return;
        }

        $this->ended = true;
        $this->dailyAlreadyDone = true;
        $this->resolveAllValidWords($wordFinder);
    }

    private function startDailySession(Carbon $date): void
    {
        $this->gameSessionId = GameSession::create([
            'user_id' => Auth::id(),
            'type' => GameType::Quartiles,
            'mode' => GameMode::Solo,
            'is_daily' => true,
            'daily_date' => $date->toDateString(),
            'settings' => ['seed' => $this->resolvedSeed],
            'state' => $this->progressState(),
            'started_at' => now()->setTimestamp($this->startedAt),
            'ended_at' => null,
        ])->id;
    }

    /**
     * Saves the current progress to the already-started session from
     * startNewSession() -- called after every scoring action. A no-op for
     * guests (no session to update) or if somehow called before a session
     * exists.
     */
    private function persistState(): void
    {
        if (! Auth::check() || $this->gameSessionId === null) {
            return;
        }

        GameSession::whereKey($this->gameSessionId)->update(['state' => $this->progressState()]);
    }
};
?>

<x-slot:title>Word Blocks — Low Stim Play</x-slot>
<x-slot:description>Play Word Blocks online — combine 2-4 letter tiles into words, find all 5 hidden Quads for a bonus, calm low-contrast visuals with no flashing or motion.</x-slot:description>
<x-slot:bodyClass>quartiles-play</x-slot:bodyClass>
<x-slot:rulesTitle>Word Blocks Rules</x-slot:rulesTitle>
<x-slot:rules>
    <h3>How to Use This Page</h3>
    <ul>
        <li>Tap tiles (in any order) to build a word, then press Submit. Tap a selected tile again to remove it.</li>
        <li>Combine 1 to 4 tiles per word — longer combinations score more.</li>
        <li>Five specific groups of 4 tiles spell a hidden "Quad" — finding one moves those tiles to their own row and earns bonus progress toward a 40-point bonus for finding all 5.</li>
        <li>Shuffle re-arranges the tiles you haven't used in a found Quad yet.</li>
        <li>Press End Game whenever you're ready to stop — every valid word the puzzle contains is then revealed, found and missed.</li>
        <li>If you're signed in, leaving and coming back later picks up right where you left off.</li>
    </ul>

    <h3>Full Rules</h3>
    <ul>
        <li>Scoring: 1 tile = 1 point, 2 tiles = 2 points, 3 tiles = 4 points, 4 tiles = 8 points.</li>
        <li>Finding all 5 Quads earns a 40-point bonus.</li>
        <li>Only real dictionary words count, checked against the same word list Word Grid uses. Each word only scores once per game.</li>
        <li>A Quad's tiles remain selectable afterward — they can still be combined with other tiles to spell more words.</li>
    </ul>
</x-slot:rules>

<div class="flex flex-col items-center w-full gap-6">
    @teleport('#game-actions')
        <div style="display: contents;">
            @if ($ended)
                @if ($dailyDate !== null)
                    {{-- A daily puzzle isn't reroll-able -- its board is fixed
                         for the whole day, same reasoning Boggle applies to a
                         room/challenge game's own New Game button. --}}
                    <a href="{{ route('daily-puzzles') }}"><button type="button" id="new-game-btn">More Daily Puzzles</button></a>
                @else
                    <button type="button" id="new-game-btn" wire:click="resetGame">New Game</button>
                @endif
            @else
                <button type="button" id="end-game-btn" wire:click="endGame">End Game</button>
            @endif
        </div>
    @endteleport

    @if ($dailyDate !== null)
        <p id="daily-badge" class="text-xs text-center text-accent font-bold">
            Daily Puzzle — {{ Carbon::parse($dailyDate)->format('M j, Y') }}
        </p>
    @endif

    <div class="flex flex-wrap items-center justify-center gap-4 w-full max-w-md">
        <div class="flex flex-col items-center gap-1 bg-surface border-2 border-surface-border rounded-lg px-4 py-2">
            <span class="text-xl font-bold text-text">{{ $score }}</span>
            <span class="text-xs text-text/65">Score</span>
        </div>
        <div class="flex flex-col items-center gap-1 bg-surface border-2 border-surface-border rounded-lg px-4 py-2">
            <span class="text-xl font-bold text-text">{{ count($foundGroups) }}/5</span>
            <span class="text-xs text-text/65">Quads</span>
        </div>
        <div class="flex flex-col items-center gap-1 bg-surface border-2 border-surface-border rounded-lg px-4 py-2">
            <span class="text-xl font-bold text-text">{{ count($foundWords) }}</span>
            <span class="text-xs text-text/65">Words</span>
        </div>
    </div>

    @if ($this->banner)
        <p class="status-line text-center" id="quartiles-banner">{{ $this->banner }}</p>
    @endif

    @unless ($ended)
        {{--
            Side by side on desktop (the grid + controls on the left, the
            growing found-words list on the right) so both are visible at
            once instead of having to scroll past the whole grid to see
            what's been found so far -- stacked back to a single column
            below lg, where there's no room for two columns side by side
            anyway. items-start (not items-center, unlike the single-
            column mobile layout) so the right column's own top aligns
            with the left's instead of vertically centering against
            whichever column is taller.
        --}}
        <div class="flex flex-col lg:flex-row lg:items-start justify-center gap-6 w-full lg:max-w-3xl">
            <div class="flex flex-col items-center gap-3 w-full max-w-md mx-auto lg:mx-0">
                <p class="status-line text-center" id="quartiles-selected-word" style="min-height: 1.5em;">
                    {{ $this->selectedWord }}
                </p>

                @foreach ($foundGroups as $groupIndex)
                    <div class="grid grid-cols-4 gap-2 w-full" id="found-group-{{ $groupIndex }}" wire:key="found-group-{{ $groupIndex }}">
                        @foreach ($groups[$groupIndex] as $tileId)
                            <button type="button" id="tile-{{ $tileId }}" wire:key="tile-{{ $tileId }}" wire:click="toggleTile({{ $tileId }})"
                                aria-pressed="{{ in_array($tileId, $selected, true) ? 'true' : 'false' }}"
                                class="quartile-tile quartile-tile-found tile-btn aspect-square min-h-14 w-14 flex items-center justify-center rounded-lg border-2 text-base font-bold tracking-wide bg-accent/15 border-accent text-accent {{ in_array($tileId, $selected, true) ? 'ring-2 ring-accent' : '' }}">
                                {{ $tiles[$tileId]['display'] }}
                            </button>
                        @endforeach
                    </div>
                @endforeach

                <div class="grid grid-cols-4 gap-2 w-full" id="unfound-tiles">
                    @foreach ($unfoundOrder as $tileId)
                        <button type="button" id="tile-{{ $tileId }}" wire:key="tile-{{ $tileId }}" wire:click="toggleTile({{ $tileId }})"
                            aria-pressed="{{ in_array($tileId, $selected, true) ? 'true' : 'false' }}"
                            class="quartile-tile tile-btn aspect-square min-h-14 w-14 flex items-center justify-center rounded-lg border-2 text-base font-bold tracking-wide bg-surface border-surface-border text-text {{ in_array($tileId, $selected, true) ? 'ring-2 ring-accent border-accent' : '' }}">
                            {{ $tiles[$tileId]['display'] }}
                        </button>
                    @endforeach
                </div>

                <div class="flex gap-3">
                    <button type="button" id="quartiles-shuffle-btn" wire:click="shuffleTiles">Shuffle</button>
                    <button type="button" id="quartiles-clear-btn" wire:click="clearSelection" @disabled($selected === [])>Clear</button>
                    <button type="button" id="quartiles-submit-btn" wire:click="submitWord" @disabled($selected === [])>Submit</button>
                </div>

                @if ($message)
                    <p class="status-line" style="color: var(--warn);">{{ $message }}</p>
                @endif
            </div>

            <div class="flex flex-col gap-2 w-full max-w-md mx-auto lg:mx-0 lg:max-w-xs lg:sticky lg:top-24">
                <p class="text-sm text-text/80 font-bold">Words found</p>

                @if ($foundWords === [])
                    <p class="text-sm text-text/65 text-center">Nothing yet.</p>
                @else
                    <div class="flex flex-wrap gap-2 w-full lg:max-h-[520px] lg:overflow-y-auto" id="found-words-list">
                        @foreach ($this->foundWordsSorted as $word => $points)
                            <span wire:key="found-word-{{ $word }}" class="text-sm bg-surface border border-surface-border rounded px-3 py-1.5 text-text">
                                {{ strtoupper($word) }} <span class="text-text/65">({{ $points }})</span>
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @else
        <div class="flex flex-col items-center gap-4 w-full max-w-2xl">
            <div class="flex flex-col items-center gap-2 w-full">
                <p class="text-sm text-text/80 font-bold">Quads</p>
                <div class="flex flex-wrap justify-center gap-2 w-full">
                    @foreach ($this->quartileAnswers as $answer)
                        <span wire:key="answer-{{ $loop->index }}"
                            class="text-sm px-3 py-2 rounded-lg border-2 {{ $answer['found'] ? 'bg-accent/15 border-accent text-accent' : 'bg-surface border-surface-border text-text/65' }}">
                            {{ strtoupper($answer['word']) }} {{ $answer['found'] ? '✓' : '' }}
                        </span>
                    @endforeach
                </div>
            </div>

            <div class="flex flex-col gap-2 w-full">
                <p class="text-sm text-text/80 font-bold">All Possible Words ({{ count($allValidWords ?? []) }})</p>
                <div class="flex flex-wrap gap-6 w-full bg-surface border-2 border-surface-border rounded-lg p-6">
                    @foreach ($this->revealByTileCount as $tileCount => $words)
                        <div class="flex flex-col gap-1 min-w-24">
                            <p class="text-xs text-text/65 font-bold border-b border-surface-border pb-2 mb-1">{{ $tileCount }} tile{{ $tileCount === 1 ? '' : 's' }}</p>
                            <ul class="flex flex-col gap-1">
                                @foreach ($words as $word)
                                    <li wire:key="reveal-{{ $word }}" class="text-sm px-2 py-1 {{ isset($foundWords[$word]) ? 'text-accent font-bold' : 'text-text/65' }}">
                                        {{ strtoupper($word) }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>

            @if ($dailyDate === now()->toDateString())
                {{-- Covers both a just-finished today's daily (this same
                     request) and a restored, already-done one
                     ($dailyAlreadyDone) -- either way there's nothing left
                     to play until tomorrow. --}}
                <x-daily-countdown :resets-at="now()->addDay()->startOfDay()->timestamp" />
            @elseif ($dailyDate === null)
                <div class="flex gap-2 w-full">
                    <input type="text" readonly value="{{ route('quartiles.play', ['seed' => $resolvedSeed]) }}"
                        onclick="this.select()" style="flex: 1;">
                    <button type="button" data-copy="{{ route('quartiles.play', ['seed' => $resolvedSeed]) }}">
                        Copy
                    </button>
                </div>

                @auth
                    <livewire:challenge-friend :game-type="GameType::Quartiles"
                        :link="route('quartiles.play', ['seed' => $resolvedSeed])"
                        wire:key="challenge-friend-quartiles-{{ $resolvedSeed }}" />
                @endauth
            @endif
        </div>
    @endif
</div>
