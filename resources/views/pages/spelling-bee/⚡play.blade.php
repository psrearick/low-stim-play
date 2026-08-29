<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Services\Boggle\Solver;
use App\Services\Puzzles\DailyPuzzle;
use App\Services\SpellingBee\PuzzleGenerator;
use App\Services\SpellingBee\RankCalculator;
use App\Services\SpellingBee\Scorer;
use App\Services\Words\CommonWordFilter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    private const MIN_WORD_LENGTH = 4;

    /**
     * Sharing a seed makes an identical puzzle reproducible -- same purpose
     * and shape as Word Blocks' own `#[Url] $seed` (docs/specs/quartiles.md
     * section 4), since nothing about a Word Hive puzzle is secret either
     * (docs/specs/spelling-bee.md section 1). Ignored by resetGame() ("New
     * Game" always generates a genuinely fresh puzzle).
     */
    #[Url]
    public ?int $seed = null;

    /**
     * A `Y-m-d` date string -- when set, the puzzle is that day's daily
     * (seed derived by DailyPuzzle::seedFor(), not $seed above). Only
     * *today's* date is locked to one signed-in play per day (see
     * mountDaily()); any other date -- an archive entry, or any date at
     * all for a guest -- is just an ordinary seeded puzzle, freely
     * replayable. Mirrors pages::quartiles.play exactly.
     */
    #[Url]
    public ?string $dailyDate = null;

    /**
     * True once mountDaily() has restored *today's* already-finished
     * attempt -- see pages::quartiles.play's own property of the same name.
     */
    public bool $dailyAlreadyDone = false;

    /**
     * All 7 puzzle letters, lowercase -- static for the whole game once
     * generated, never mutated after mount()/resetGame(). Includes $center.
     *
     * @var list<string>
     */
    public array $letters = [];

    public string $center = '';

    /**
     * The 6 non-center letters, in their current display order -- separate
     * from $letters so shuffleLetters() can reorder the hexagon ring
     * without touching the canonical letter set.
     *
     * @var list<string>
     */
    public array $outerLetters = [];

    /**
     * Found words and the points they were worth, keyed by word -- same
     * shape as Boggle/Word Blocks' own $foundWords.
     *
     * @var array<string, int>
     */
    public array $foundWords = [];

    public int $score = 0;

    /**
     * The score a player would have if they found every one of the
     * puzzle's answer words -- computed once at generation from
     * Scorer::maxPossible() and kept as a plain public int (safe to
     * expose; unlike the full word list, a single point total reveals
     * nothing about which specific words are valid). Needed by `rank`
     * throughout play, not just at the end.
     */
    public int $maxScore = 0;

    /**
     * word length => how many of the puzzle's answer words are that long,
     * e.g. `[4 => 11, 5 => 8, ...]` -- computed once at generation
     * alongside $maxScore, and safe for the same reason: a length's own
     * word *count* reveals nothing about which specific words qualify
     * (docs/concerns.md #9's "Four Letters (5/11)" progress columns).
     * Never the words themselves -- those stay hidden until endGame()
     * exactly as before.
     *
     * @var array<int, int>
     */
    public array $totalWordsByLength = [];

    public string $currentWord = '';

    public ?string $message = null;

    /**
     * The seed the current puzzle was actually generated with -- see
     * pages::quartiles.play's own property of the same name.
     */
    public int $resolvedSeed = 0;

    public int $startedAt;

    public bool $ended = false;

    /**
     * Every valid word the puzzle contains -- null until the game is
     * explicitly ended. Deliberately never populated during play and never
     * persisted in $state: PuzzleGenerator::wordsFor() cheaply re-derives
     * this on demand from $letters/$center (both already public/visible)
     * at endGame() time, so the full answer list is never sent to the
     * client before the player has actually finished (see
     * docs/specs/spelling-bee.md section 4).
     *
     * @var list<string>|null
     */
    public ?array $allWords = null;

    public ?int $gameSessionId = null;

    public function mount(PuzzleGenerator $generator, DailyPuzzle $dailyPuzzle): void
    {
        if ($this->dailyDate !== null) {
            $this->mountDaily($generator, $dailyPuzzle);

            return;
        }

        if ($this->seed === null && Auth::check() && $this->resumeInProgressGame($generator)) {
            return;
        }

        $this->loadNewPuzzle($generator, $this->seed);
        $this->startedAt = now()->timestamp;
        $this->startNewSession();
    }

    public function pressLetter(string $letter): void
    {
        if ($this->ended || ! in_array($letter, $this->letters, true)) {
            return;
        }

        $this->currentWord .= $letter;
    }

    public function backspace(): void
    {
        $this->currentWord = mb_substr($this->currentWord, 0, -1);
    }

    public function clearWord(): void
    {
        $this->currentWord = '';
    }

    public function submitWord(Solver $solver, Scorer $scorer, CommonWordFilter $filter): void
    {
        $word = strtolower(trim($this->currentWord));
        $this->currentWord = '';

        if ($this->ended || $word === '') {
            return;
        }

        if (isset($this->foundWords[$word])) {
            $this->message = 'Already found.';

            return;
        }

        if (mb_strlen($word) < self::MIN_WORD_LENGTH) {
            $this->message = 'Too short.';

            return;
        }

        if (! str_contains($word, $this->center)) {
            $this->message = 'Missing the center letter.';

            return;
        }

        if (! $this->usesOnlyPuzzleLetters($word)) {
            $this->message = 'Uses a letter not in the puzzle.';

            return;
        }

        // The exclusion check mirrors PuzzleGenerator's own -- without it,
        // a player could still score an excluded word by typing it
        // directly, even though it can never appear as a generated answer
        // (docs/specs/spelling-bee.md section 3/4). No distinct message:
        // this should read exactly like any other non-dictionary word.
        if (! $solver->isValidWord($word) || $filter->excluding([$word]) === []) {
            $this->message = 'Not a word.';

            return;
        }

        $points = $scorer->pointsFor($word, $this->letters);
        $this->foundWords[$word] = $points;
        $this->score += $points;
        $this->message = null;

        $this->persistState();
    }

    public function shuffleLetters(): void
    {
        if ($this->ended) {
            return;
        }

        $this->outerLetters = collect($this->outerLetters)->shuffle()->values()->all();
    }

    public function endGame(PuzzleGenerator $generator): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;
        $this->currentWord = '';
        $this->allWords = $generator->wordsFor($this->letters, $this->center);

        $stats = [
            'found_words' => $this->foundWords,
            'words_found' => count($this->foundWords),
            'pangrams_found' => count(array_filter(array_keys($this->foundWords), fn (string $word) => $this->isPangram($word))),
            'total_valid_words' => count($this->allWords),
            'max_score' => $this->maxScore,
            'rank' => $this->rank,
        ];

        // A signed-in user's session already exists (created at mount/New
        // Game time -- see startNewSession()) -- update it in place rather
        // than creating a second row. Guests were never given an early row,
        // so they still get one created here exactly as every other solo
        // game's own v1 did.
        if (Auth::check() && $this->gameSessionId !== null) {
            $session = GameSession::find($this->gameSessionId);
            $session->update(['state' => $this->progressState(), 'ended_at' => now()]);
        } else {
            $session = GameSession::create([
                'type' => GameType::SpellingBee,
                'mode' => GameMode::Solo,
                'settings' => ['seed' => $this->resolvedSeed],
                'started_at' => now()->setTimestamp($this->startedAt),
                'ended_at' => now(),
            ]);
            $this->gameSessionId = $session->id;
        }

        Auth::check()
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

    public function getRankProperty(): string
    {
        return app(RankCalculator::class)->rankFor($this->score, $this->maxScore);
    }

    /**
     * $foundWords, alphabetically -- same "insertion order isn't a useful
     * reading order" reasoning as Word Blocks' own foundWordsSorted.
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
     * $foundWords grouped by length, ascending, words alphabetical within
     * each group (docs/concerns.md #9: the "Four Letters (5/11)" progress
     * columns) -- the live-play counterpart to getWordsByLengthProperty()
     * below, which only ever has anything once the game's ended. Safe to
     * show during play unlike that one: every word here is something the
     * player already found, not a spoiler.
     *
     * @return array<int, array<string, int>>
     */
    public function getFoundWordsByLengthProperty(): array
    {
        $groups = [];

        foreach ($this->foundWordsSorted as $word => $points) {
            $groups[mb_strlen($word)][$word] = $points;
        }

        ksort($groups);

        return $groups;
    }

    /**
     * $allWords grouped by length, ascending -- same grouping Boggle's own
     * reveal panel uses. Empty until the game ends.
     *
     * @return array<int, list<string>>
     */
    public function getWordsByLengthProperty(): array
    {
        $groups = [];

        foreach ($this->allWords ?? [] as $word) {
            $groups[mb_strlen($word)][] = $word;
        }

        ksort($groups);

        return $groups;
    }

    private function isPangram(string $word): bool
    {
        return app(Scorer::class)->isPangram($word, $this->letters);
    }

    private function usesOnlyPuzzleLetters(string $word): bool
    {
        $letterSet = array_flip($this->letters);

        foreach (mb_str_split($word) as $char) {
            if (! isset($letterSet[$char])) {
                return false;
            }
        }

        return true;
    }

    private function loadNewPuzzle(PuzzleGenerator $generator, ?int $seed = null): void
    {
        $puzzle = $generator->generate($seed);
        $this->letters = $puzzle['letters'];
        $this->center = $puzzle['center'];
        $this->outerLetters = array_values(array_diff($puzzle['letters'], [$puzzle['center']]));
        $this->maxScore = app(Scorer::class)->maxPossible($puzzle['words'], $puzzle['letters']);
        $this->totalWordsByLength = $this->countsByLength($puzzle['words']);
        $this->resolvedSeed = $puzzle['seed'];
    }

    /**
     * @param  list<string>  $words
     * @return array<int, int>
     */
    private function countsByLength(array $words): array
    {
        $counts = [];

        foreach ($words as $word) {
            $counts[mb_strlen($word)] = ($counts[mb_strlen($word)] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * Resets every progress field back to a fresh-game state, leaving
     * $letters/$center/$outerLetters/$maxScore/$totalWordsByLength
     * untouched -- called by resetGame() after it generates a new puzzle
     * (which does replace all of those).
     */
    private function resetProgress(): void
    {
        $this->foundWords = [];
        $this->score = 0;
        $this->currentWord = '';
        $this->message = null;
        $this->ended = false;
        $this->allWords = null;
        $this->startedAt = now()->timestamp;
        $this->gameSessionId = null;
    }

    /**
     * @return array{letters: list<string>, center: string, outerLetters: list<string>, foundWords: array<string, int>, score: int, maxScore: int}
     */
    private function progressState(): array
    {
        return [
            'letters' => $this->letters,
            'center' => $this->center,
            'outerLetters' => $this->outerLetters,
            'foundWords' => $this->foundWords,
            'score' => $this->score,
            'maxScore' => $this->maxScore,
            'totalWordsByLength' => $this->totalWordsByLength,
        ];
    }

    /**
     * Hydrates $this from a signed-in user's own saved in-progress
     * GameSession, if one exists -- mirrors pages::quartiles.play's own
     * resumeInProgressGame() exactly.
     */
    private function resumeInProgressGame(PuzzleGenerator $generator): bool
    {
        $session = GameSession::query()
            ->where('user_id', Auth::id())
            ->where('type', GameType::SpellingBee)
            ->where('is_daily', false)
            ->whereNull('ended_at')
            ->first();

        if ($session === null) {
            return false;
        }

        $this->hydrateFromState($session, $generator);

        return true;
    }

    private function startNewSession(): void
    {
        if (! Auth::check()) {
            return;
        }

        $attributes = [
            'user_id' => Auth::id(),
            'type' => GameType::SpellingBee,
            'mode' => GameMode::Solo,
            'is_daily' => false,
            'settings' => ['seed' => $this->resolvedSeed],
            'state' => $this->progressState(),
            'started_at' => now()->setTimestamp($this->startedAt),
            'ended_at' => null,
        ];

        $existing = GameSession::query()
            ->where('user_id', Auth::id())
            ->where('type', GameType::SpellingBee)
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

    private function mountDaily(PuzzleGenerator $generator, DailyPuzzle $dailyPuzzle): void
    {
        $date = Carbon::parse($this->dailyDate)->startOfDay();
        $seed = $dailyPuzzle->seedFor(GameType::SpellingBee, $date);

        if ($date->isToday() && Auth::check()) {
            $session = $this->findDailySession($date);

            if ($session !== null) {
                $this->restoreDailySession($session, $generator);

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
            ->where('type', GameType::SpellingBee)
            ->where('is_daily', true)
            ->where('daily_date', $date->toDateString())
            ->first();
    }

    private function restoreDailySession(GameSession $session, PuzzleGenerator $generator): void
    {
        $this->hydrateFromState($session, $generator);

        if ($session->ended_at === null) {
            return;
        }

        $this->ended = true;
        $this->dailyAlreadyDone = true;
        $this->allWords = app(PuzzleGenerator::class)->wordsFor($this->letters, $this->center);
    }

    private function startDailySession(Carbon $date): void
    {
        $this->gameSessionId = GameSession::create([
            'user_id' => Auth::id(),
            'type' => GameType::SpellingBee,
            'mode' => GameMode::Solo,
            'is_daily' => true,
            'daily_date' => $date->toDateString(),
            'settings' => ['seed' => $this->resolvedSeed],
            'state' => $this->progressState(),
            'started_at' => now()->setTimestamp($this->startedAt),
            'ended_at' => null,
        ])->id;
    }

    private function hydrateFromState(GameSession $session, PuzzleGenerator $generator): void
    {
        $state = $session->state ?? [];

        $this->letters = $state['letters'] ?? [];
        $this->center = $state['center'] ?? '';
        $this->outerLetters = $state['outerLetters'] ?? [];
        $this->foundWords = $state['foundWords'] ?? [];
        $this->score = $state['score'] ?? 0;
        $this->maxScore = $state['maxScore'] ?? 0;
        // Re-derived, not defaulted to [], for a session saved before this
        // property existed (docs/concerns.md #9) -- wordsFor() cheaply
        // (~15ms) re-enumerates the same answer list generation itself
        // already produced, exactly like endGame()'s own reveal does, so
        // an in-progress game started before this feature shipped still
        // gets real per-length totals on its very next reload instead of
        // an empty progress panel.
        $this->totalWordsByLength = $state['totalWordsByLength']
            ?? $this->countsByLength($generator->wordsFor($this->letters, $this->center));
        $this->resolvedSeed = $session->settings['seed'] ?? 0;
        $this->startedAt = $session->started_at->timestamp;
        $this->gameSessionId = $session->id;
    }

    /**
     * Saves progress after every scoring submission -- called only from
     * submitWord(), same as Word Blocks' own persistState() being called
     * only after a successful score (shuffling here is purely cosmetic
     * display order, not worth a write). A no-op for guests.
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

<x-slot:title>Word Hive — Low Stim Play</x-slot>
<x-slot:description>Play Word Hive online — spell words of 4+ letters using 7 given letters, every word must include the center letter, calm low-contrast visuals with no flashing or motion.</x-slot:description>
<x-slot:bodyClass>spelling-bee-play</x-slot:bodyClass>
<x-slot:rulesTitle>Word Hive Rules</x-slot:rulesTitle>
<x-slot:rules>
    <h3>How to Use This Page</h3>
    <ul>
        <li>Tap letters (in any order), or type on your keyboard, to build a word, then press Submit or Enter.</li>
        <li>Every word must be 4 or more letters and include the center letter (highlighted).</li>
        <li>Letters can repeat any number of times within a word.</li>
        <li>Shuffle re-arranges the outer letters -- purely visual, it doesn't change the puzzle.</li>
        <li>Press End Game whenever you're ready to stop -- every valid word the puzzle contains is then revealed, found and missed.</li>
        <li>If you're signed in, leaving and coming back later picks up right where you left off.</li>
    </ul>

    <h3>Full Rules</h3>
    <ul>
        <li>Scoring: a 4-letter word is worth 1 point; each letter beyond 4 adds 1 more point.</li>
        <li>A word using all 7 letters at least once (a pangram) earns a 7-point bonus.</li>
        <li>Only real dictionary words count, checked against the same word list Word Grid uses. Each word only scores once per game.</li>
        <li>Your rank rises as your score approaches the maximum possible for this puzzle -- Hive Master means you found every word.</li>
    </ul>
</x-slot:rules>

<div class="flex flex-col items-center w-full gap-6">
    @teleport('#game-actions')
        <div style="display: contents;">
            @if ($ended)
                @if ($dailyDate !== null)
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
            <span class="text-xl font-bold text-text" id="spelling-bee-rank">{{ $this->rank }}</span>
            <span class="text-xs text-text/65">Rank</span>
        </div>
        <div class="flex flex-col items-center gap-1 bg-surface border-2 border-surface-border rounded-lg px-4 py-2">
            <span class="text-xl font-bold text-text">{{ count($foundWords) }}</span>
            <span class="text-xs text-text/65">Words</span>
        </div>
    </div>

    @unless ($ended)
        <div class="flex flex-col lg:flex-row lg:items-start justify-center gap-6 w-full lg:max-w-3xl">
            <div class="flex flex-col items-center gap-3 w-full max-w-sm mx-auto lg:mx-0">
                {{--
                    One merged word display, not a status line plus a real
                    text `<input>` showing the same value twice
                    (docs/concerns.md) -- a plain, non-editable div instead
                    of an `<input>`, so mobile never gets a virtual
                    keyboard/zoom from tapping it (letters can still only
                    be entered by tapping the hive or a physical keyboard;
                    see the @script block below for the latter) and there's
                    no native caret to reposition by clicking earlier in
                    the word. Desktop physical-keyboard presses are
                    captured by a page-wide keydown listener instead of
                    `wire:model`, so this div never needs real DOM focus to
                    work -- nothing to "click away" from. Server-rendered
                    from $currentWord/$center exactly like the old status
                    line was, so every existing `assertSee('WORD')`-style
                    test still finds the word here unchanged.
                --}}
                <div id="spelling-bee-current-word" class="spelling-bee-word-display" tabindex="0" role="textbox"
                    aria-readonly="true" aria-label="Current word">
                    @if ($currentWord === '')
                        <span class="spelling-bee-placeholder">Type or tap letters&hellip;</span>
                    @else
                        {{--
                            Built as one concatenated string, not a
                            `@foreach` emitting one `<span>` per line --
                            Blade preserves the whitespace/newlines between
                            loop iterations verbatim, and since these are
                            inline elements, a browser renders that
                            whitespace as real spaces between letters
                            (confirmed live: assertSee('CLICK') failed
                            against "C L I C K"). No `e()`/htmlspecialchars
                            needed per letter -- $char is always one of the
                            puzzle's own a-z letters, never arbitrary input.
                        --}}
                        {!! collect(mb_str_split($currentWord))
                            ->map(fn (string $char) => '<span'.($char === $center ? ' class="spelling-bee-key-letter"' : '').'>'.strtoupper($char).'</span>')
                            ->implode('') !!}
                        {{--
                            A static bar, not an animated blink -- app.css's
                            global `* { animation: none !important; }` rule
                            ("No flashing, blinking, or motion of any kind
                            -- the whole point of this app") rules out a
                            literal blinking cursor, so this keeps the
                            requested distinct-colored insertion marker
                            without reintroducing motion this app
                            deliberately never has anywhere else.
                        --}}
                        <span class="spelling-bee-cursor" aria-hidden="true"></span>
                    @endif
                </div>

                {{--
                    A real tessellating honeycomb (docs/concerns.md #8),
                    not just a hexagon-ish center-row-plus-wrapped-row
                    approximation -- see app.css's own .hive rules for the
                    2-3-2 flower layout math, and <x-hive-hex> for why each
                    cell is a real SVG polygon rather than a clip-path'd
                    box. $outerLetters[0..1] sit in the top row, [2]/[3]
                    flank the center in the middle row, [4..5] sit in the
                    bottom row; shuffleLetters() reorders the same array
                    shuffled by the old layout, so a shuffle still just
                    reassigns which letter sits in which slot.
                --}}
                <div class="hive" id="letters-outer">
                    <div class="hive-row hive-row-top">
                        <x-hive-hex id="letter-{{ $outerLetters[0] }}" wire:key="letter-{{ $outerLetters[0] }}" wire:click="pressLetter('{{ $outerLetters[0] }}')" :letter="$outerLetters[0]" :disabled="$ended" />
                        <x-hive-hex id="letter-{{ $outerLetters[1] }}" wire:key="letter-{{ $outerLetters[1] }}" wire:click="pressLetter('{{ $outerLetters[1] }}')" :letter="$outerLetters[1]" :disabled="$ended" />
                    </div>
                    <div class="hive-row hive-row-mid">
                        <x-hive-hex id="letter-{{ $outerLetters[2] }}" wire:key="letter-{{ $outerLetters[2] }}" wire:click="pressLetter('{{ $outerLetters[2] }}')" :letter="$outerLetters[2]" :disabled="$ended" />
                        <x-hive-hex id="letter-center" wire:click="pressLetter('{{ $center }}')" :letter="$center" :center="true" :disabled="$ended" />
                        <x-hive-hex id="letter-{{ $outerLetters[3] }}" wire:key="letter-{{ $outerLetters[3] }}" wire:click="pressLetter('{{ $outerLetters[3] }}')" :letter="$outerLetters[3]" :disabled="$ended" />
                    </div>
                    <div class="hive-row hive-row-bottom">
                        <x-hive-hex id="letter-{{ $outerLetters[4] }}" wire:key="letter-{{ $outerLetters[4] }}" wire:click="pressLetter('{{ $outerLetters[4] }}')" :letter="$outerLetters[4]" :disabled="$ended" />
                        <x-hive-hex id="letter-{{ $outerLetters[5] }}" wire:key="letter-{{ $outerLetters[5] }}" wire:click="pressLetter('{{ $outerLetters[5] }}')" :letter="$outerLetters[5]" :disabled="$ended" />
                    </div>
                </div>

                <div class="flex gap-3">
                    <button type="button" id="spelling-bee-shuffle-btn" wire:click="shuffleLetters">Shuffle</button>
                    <button type="button" id="spelling-bee-backspace-btn" wire:click="backspace">Delete</button>
                    <button type="button" id="spelling-bee-submit-btn" wire:click="submitWord" @disabled($currentWord === '')>Submit</button>
                </div>

                @if ($message)
                    <p class="status-line" style="color: var(--warn);">{{ $message }}</p>
                @endif
            </div>

            <div class="flex flex-col gap-2 w-full max-w-sm mx-auto lg:mx-0 lg:max-w-xs lg:sticky lg:top-24">
                <p class="text-sm text-text/80 font-bold">Words found</p>

                {{--
                    Per-length progress columns (docs/concerns.md #9) --
                    one column per word length the puzzle actually has
                    answers for (even a length with 0 found yet still gets
                    its own "(0/N)" column, so the total is visible from
                    the start), each headed by a found/total count. Only
                    already-found words are ever listed under a column --
                    $totalWordsByLength is a safe aggregate count computed
                    at generation time (see that property's own docblock),
                    but the *words* themselves stay exactly as hidden
                    during play as they always have.
                --}}
                @if ($totalWordsByLength === [])
                    <p class="text-sm text-text/65 text-center">Nothing yet.</p>
                @else
                    <div class="flex flex-wrap gap-4 w-full lg:max-h-[520px] lg:overflow-y-auto" id="found-words-list">
                        @foreach ($totalWordsByLength as $length => $total)
                            @php $found = $this->foundWordsByLength[$length] ?? []; @endphp
                            <div wire:key="length-col-{{ $length }}" id="length-col-{{ $length }}" class="flex flex-col gap-1 min-w-20">
                                <p class="text-xs text-text/65 font-bold border-b border-surface-border pb-1 mb-1">
                                    {{ $length }} Letters ({{ count($found) }}/{{ $total }})
                                </p>
                                <ul class="flex flex-col gap-1">
                                    @foreach ($found as $word => $points)
                                        <li wire:key="found-word-{{ $word }}" class="text-sm text-text">
                                            {{ strtoupper($word) }} <span class="text-text/65">({{ $points }})</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @else
        <div class="flex flex-col items-center gap-4 w-full max-w-2xl">
            <div class="flex flex-col gap-2 w-full">
                <p class="text-sm text-text/80 font-bold">All Possible Words ({{ count($allWords ?? []) }})</p>
                <div class="flex flex-wrap gap-6 w-full bg-surface border-2 border-surface-border rounded-lg p-6">
                    @foreach ($this->wordsByLength as $length => $words)
                        <div class="flex flex-col gap-1 min-w-24">
                            <p class="text-xs text-text/65 font-bold border-b border-surface-border pb-2 mb-1">{{ $length }} letters</p>
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
                <x-daily-countdown :resets-at="now()->addDay()->startOfDay()->timestamp" />
            @elseif ($dailyDate === null)
                <div class="flex gap-2 w-full">
                    <input type="text" readonly value="{{ route('spelling-bee.play', ['seed' => $resolvedSeed]) }}"
                        onclick="this.select()" style="flex: 1;">
                    <button type="button" data-copy="{{ route('spelling-bee.play', ['seed' => $resolvedSeed]) }}">
                        Copy
                    </button>
                </div>

                @auth
                    <livewire:challenge-friend :game-type="GameType::SpellingBee"
                        :link="route('spelling-bee.play', ['seed' => $resolvedSeed])"
                        wire:key="challenge-friend-spelling-bee-{{ $resolvedSeed }}" />
                @endauth
            @endif
        </div>
    @endif
</div>

@script
<script>
    // Physical-keyboard capture for #spelling-bee-current-word
    // (docs/concerns.md) -- that div is deliberately not a real `<input>`
    // (see its own docblock in the Blade above), so there's nothing for a
    // browser to attach native typing to. A page-wide keydown listener
    // instead forwards single-letter/Backspace/Enter presses to the same
    // pressLetter()/backspace()/submitWord() actions the hive's own tap
    // buttons already call via wire:click -- keyboard entry becomes just
    // another way to trigger the exact same server round trip a tap
    // already does, not a second, differently-behaved input path.
    //
    // Not gated to desktop only: a device with an attached physical
    // keyboard (a tablet with a keyboard case, say) should still be able
    // to type, and there's no reliable way to detect "has a keyboard"
    // ahead of an actual keydown anyway. What this *doesn't* do is force
    // real DOM focus onto the div beforehand or steal it back on blur --
    // since the listener lives on `document`, typing works regardless of
    // which element (if any) has focus, so "you should not be able to
    // click away from it" is true by construction rather than by fighting
    // the browser for focus.
    document.addEventListener('keydown', (event) => {
        if ($wire.ended || event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        // Skip while a real editable field elsewhere on the page (the
        // room-name field in the site menu drawer, the read-only share
        // link once the game has ended, ...) is legitimately focused, so
        // this listener doesn't steal keystrokes meant for it.
        const activeTag = document.activeElement?.tagName;
        if (activeTag === 'INPUT' || activeTag === 'TEXTAREA') {
            return;
        }

        if (event.key === 'Backspace') {
            event.preventDefault();
            $wire.backspace();
            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            $wire.submitWord();
            return;
        }

        if (event.key.length === 1 && $wire.letters.includes(event.key.toLowerCase())) {
            event.preventDefault();
            $wire.pressLetter(event.key.toLowerCase());
        }
    });

    // Best-effort only (see the docblock above for why this isn't paired
    // with a blur handler that fights the browser for focus back) -- a
    // plain non-editable div never opens a mobile virtual keyboard on
    // focus, so autofocusing it is harmless on touch devices too.
    document.getElementById('spelling-bee-current-word')?.focus({ preventScroll: true });
</script>
@endscript
