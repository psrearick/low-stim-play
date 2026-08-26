<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\WordleLetterStatus;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Services\Boggle\Solver;
use App\Services\Puzzles\DailyPuzzle;
use App\Services\Wordle\AnswerPicker;
use App\Services\Wordle\GuessEvaluator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    private const WORD_LENGTH = AnswerPicker::WORD_LENGTH;

    private const MAX_GUESSES = 6;

    /**
     * An existing solo game's session id, shared as an async challenge link
     * (`route('wordle.share', ...)`). When set, this play-through joins
     * that same GameSession (same secret answer, resolved server-side, not
     * a public seed -- see docs/specs/wordle.md section 3) rather than
     * creating a new one, exactly like Boggle's own `$challenge` does.
     */
    #[Url]
    public ?int $challenge = null;

    /**
     * A `Y-m-d` date string -- when set, the answer is that day's daily
     * (seed derived by DailyPuzzle::seedFor(), never assigned to any
     * public property -- see resolveAnswer()). Only *today's* date is
     * locked to one signed-in play per day; any other date -- an archive
     * entry, or any date at all for a guest -- is just an ordinary seeded
     * puzzle, freely replayable. See mountDaily().
     */
    #[Url]
    public ?string $dailyDate = null;

    public bool $isDaily = false;

    /**
     * True once mountDaily() has restored *today's* already-finished
     * attempt -- distinct from $ended (true for every ended game) so the
     * reveal screen can show a "come back tomorrow" countdown instead of
     * the ordinary challenge-link box, which wouldn't mean anything for a
     * fixed daily puzzle.
     */
    public bool $dailyAlreadyDone = false;

    /**
     * True once this play-through is confirmed tied to an existing shared
     * session (a `$challenge`), as opposed to a plain solo game that merely
     * *becomes* shareable after the fact. Distinct from `gameSessionId`
     * because that's populated for plain solo games too -- this flag is
     * what actually gates session-reuse and blocks persistState() from
     * writing into a session another player's own guesses might also be
     * reading (see persistState()'s own docblock).
     */
    public bool $isSharedSession = false;

    public ?int $gameSessionId = null;

    /**
     * True once the pre-game setup screen's "Start Game" has actually
     * begun this playthrough -- same shape as Yahtzee's own `$started`
     * (`docs/TODO.md`'s former Task 28). Only the plain "brand new solo
     * game" path (no daily, no challenge, no resumable in-progress
     * session) ever renders the setup screen; resuming, joining a
     * challenge, or restoring an already-in-progress/finished daily all
     * set this true immediately in mount() -- none of those has a
     * meaningful "before you begin" decision left to make, since Hard
     * Mode is already fixed by whatever session they're rejoining.
     */
    public bool $started = false;

    /**
     * Whether every subsequent guess must reuse each already-revealed
     * correct/present letter (docs/specs/wordle.md section 3). Chosen on
     * the pre-game setup screen, before $started flips true -- never
     * editable mid-game (see toggleHardMode()).
     */
    public bool $hardMode = false;

    /**
     * Every guess submitted so far, in order.
     *
     * @var list<array{word: string, result: list<string>}>
     */
    public array $guesses = [];

    /**
     * The running hard-mode requirement set, folded in after every guess --
     * see GuessEvaluator::mergeHardModeRequirements().
     *
     * @var array{positions: array<int, ?string>, minCounts: array<string, int>}
     */
    public array $hardModeRequirements = [];

    /**
     * The in-progress guess being typed/built, not yet submitted. Always
     * safe to be public -- it's only ever what the player is currently
     * typing, never touches the answer.
     */
    public string $currentGuess = '';

    /**
     * Which column of the in-progress row is targeted for the *next*
     * typed letter -- null means "append at the end," the original
     * (and still default) behavior. Set by tapping a tile in the
     * current row (see selectCell()), so a player can fix a single
     * already-typed letter instead of backspacing back to it and
     * retyping everything after it (`docs/TODO.md`'s former Task 29).
     * Always within `[0, mb_strlen($currentGuess)]` -- never a column
     * past the next not-yet-typed one, since there's nothing there to
     * select yet.
     */
    public ?int $selectedCol = null;

    public bool $ended = false;

    public bool $won = false;

    /**
     * The secret answer, populated *only* once $ended is true (a win, a
     * loss, or restoring an already-finished session/daily read-only) --
     * see docs/specs/wordle.md section 1: this must never be assigned here
     * before that point, since every public property is part of what
     * reaches the browser on every render.
     */
    public ?string $answerReveal = null;

    public int $startedAt;

    public ?string $message = null;

    public function mount(GuessEvaluator $evaluator): void
    {
        $this->hardModeRequirements = $evaluator->emptyHardModeRequirements(self::WORD_LENGTH);

        if ($this->dailyDate !== null) {
            $this->isDaily = true;
            $this->mountDaily();

            return;
        }

        if ($this->challenge !== null) {
            $this->joinChallenge($this->challenge);
            $this->started = true;

            return;
        }

        if (Auth::check() && $this->resumeInProgressGame()) {
            $this->started = true;

            return;
        }

        // Plain brand-new game -- the only path with a real "before you
        // begin" choice (Hard Mode) left to make, so this is the one case
        // that stops here and shows the setup screen instead of starting
        // immediately. startGame() (bound to that screen's own button)
        // does the actual startNewGame() call once Hard Mode is chosen.
    }

    /**
     * Actually begins a playthrough once the pre-game setup screen's Hard
     * Mode choice is confirmed -- the only place `$started` flips true for
     * a plain new game or a first-time daily attempt (see mount()'s and
     * mountDaily()'s own docblocks for why every other path sets it
     * immediately instead). A no-op if already started, since this is a
     * public Livewire action a client could otherwise call again mid-game.
     */
    public function startGame(AnswerPicker $answerPicker, DailyPuzzle $dailyPuzzle): void
    {
        if ($this->started) {
            return;
        }

        if (! $this->isDaily) {
            $this->startNewGame($answerPicker);
            $this->started = true;

            return;
        }

        $date = Carbon::parse($this->dailyDate)->startOfDay();
        $this->resetProgress();
        $this->startedAt = now()->timestamp;

        if ($date->isToday() && Auth::check()) {
            $this->startDailySession($date, $answerPicker, $dailyPuzzle);
        }

        $this->started = true;
    }

    public function pressKey(string $letter): void
    {
        if (! $this->started || $this->ended) {
            return;
        }

        $letter = strtolower($letter);

        if ($this->selectedCol !== null) {
            $chars = mb_str_split($this->currentGuess);
            $chars[$this->selectedCol] = $letter;
            $this->currentGuess = implode('', $chars);

            // Auto-advance to the next column -- purely index-based, not
            // dependent on the string's own length, since overwriting an
            // already-fully-typed guess never changes that length at all.
            // Once the column just filled was the last one, there's
            // nothing left to advance into, so drop back to plain append
            // mode rather than re-selecting the last column forever.
            $this->selectedCol = $this->selectedCol + 1 < self::WORD_LENGTH
                ? $this->selectedCol + 1
                : null;

            return;
        }

        if (mb_strlen($this->currentGuess) >= self::WORD_LENGTH) {
            return;
        }

        $this->currentGuess .= $letter;
    }

    public function backspace(): void
    {
        if (! $this->started || $this->ended) {
            return;
        }

        $this->currentGuess = mb_substr($this->currentGuess, 0, -1);
        // A specific-tile selection no longer means anything once the
        // string it pointed into has just shifted underneath it -- back
        // to plain append-at-the-end mode.
        $this->selectedCol = null;
    }

    /**
     * Targets column $col of the in-progress row for the next typed
     * letter (see $selectedCol's own docblock). Only a column already
     * typed, or the very next untyped one, is selectable -- tapping
     * further ahead than that would have nothing to actually select.
     */
    public function selectCell(int $col): void
    {
        if (! $this->started || $this->ended) {
            return;
        }

        if ($col < 0 || $col > mb_strlen($this->currentGuess) || $col >= self::WORD_LENGTH) {
            return;
        }

        $this->selectedCol = $col;
    }

    public function submitGuess(AnswerPicker $answerPicker, GuessEvaluator $evaluator, DailyPuzzle $dailyPuzzle, Solver $dictionary): void
    {
        if (! $this->started || $this->ended) {
            return;
        }

        $guess = strtolower(trim($this->currentGuess));
        $this->message = null;

        if (mb_strlen($guess) !== self::WORD_LENGTH || ! ctype_alpha($guess)) {
            $this->message = 'Guess must be '.self::WORD_LENGTH.' letters.';

            return;
        }

        if (! $dictionary->isValidWord($guess)) {
            $this->message = 'Not a word.';

            return;
        }

        if ($this->hardMode) {
            $violation = $evaluator->hardModeViolation($this->hardModeRequirements, $guess);

            if ($violation !== null) {
                $this->message = $violation;

                return;
            }
        }

        $answer = $this->resolveAnswer($answerPicker, $dailyPuzzle);
        $result = $evaluator->evaluate($answer, $guess);

        $this->guesses[] = [
            'word' => $guess,
            'result' => array_map(fn (WordleLetterStatus $status) => $status->value, $result),
        ];
        $this->hardModeRequirements = $evaluator->mergeHardModeRequirements($this->hardModeRequirements, $guess, $result);
        $this->currentGuess = '';
        $this->selectedCol = null;

        if ($guess === $answer) {
            $this->won = true;
            $this->finishGame($answer);

            return;
        }

        if (count($this->guesses) >= self::MAX_GUESSES) {
            $this->finishGame($answer);

            return;
        }

        $this->persistState();
    }

    public function toggleHardMode(): void
    {
        if ($this->started) {
            return;
        }

        $this->hardMode = ! $this->hardMode;
    }

    /**
     * Returns to the pre-game setup screen rather than immediately
     * starting a fresh game -- Hard Mode is a setup-time choice (see
     * $started's own docblock), so "New Game" needs to offer that choice
     * again, not silently reuse whatever it was last set to. $hardMode
     * itself is deliberately left untouched by resetProgress() below (a
     * player's own preference persists across New Game), so the setup
     * screen simply reopens pre-filled with their last choice.
     */
    public function resetGame(): void
    {
        if ($this->isSharedSession || $this->isDaily) {
            return;
        }

        $this->resetProgress();
        $this->started = false;
    }

    /**
     * Best-known status per letter across every guess so far, for the
     * on-screen keyboard's own recoloring -- correct beats present beats
     * absent regardless of guess order, since a later guess can only ever
     * reveal more about a letter, not less.
     *
     * @return array<string, string>
     */
    public function getKeyboardStatusProperty(): array
    {
        $rank = ['absent' => 0, 'present' => 1, 'correct' => 2];
        $status = [];

        foreach ($this->guesses as $guess) {
            foreach (mb_str_split($guess['word']) as $i => $letter) {
                $current = $guess['result'][$i];

                if (! isset($status[$letter]) || $rank[$current] > $rank[$status[$letter]]) {
                    $status[$letter] = $current;
                }
            }
        }

        return $status;
    }

    /**
     * The single source of truth for a tile/key's per-status look --
     * shared by the guess grid, the on-screen keyboard, and the legend, so
     * all three can never silently drift out of sync with each other.
     */
    public function tileClasses(?string $status): string
    {
        return match ($status) {
            'correct' => 'bg-accent border-accent text-bg',
            'present' => 'bg-warn/20 border-warn border-dashed text-text',
            'absent' => 'bg-die border-die-border text-text/50',
            default => 'bg-surface border-surface-border text-text',
        };
    }

    /**
     * The small non-color signal paired with each status (this app's
     * "never rely on color alone" rule) -- see tileClasses()'s own
     * docblock for why this is the one place it's defined.
     */
    public function tileMark(?string $status): string
    {
        return match ($status) {
            'correct' => '✓',
            'present' => '~',
            default => '',
        };
    }

    /**
     * Other players' final results for this same shared session -- empty
     * until they've also finished. Mirrors Boggle's own
     * getOtherParticipantsProperty().
     *
     * @return \Illuminate\Support\Collection<int, GameParticipant>
     */
    public function getOtherParticipantsProperty()
    {
        if (! $this->ended || ! $this->isSharedSession || $this->gameSessionId === null) {
            return collect();
        }

        return GameParticipant::with('user')
            ->where('game_session_id', $this->gameSessionId)
            ->when(Auth::check(), fn ($query) => $query->where('user_id', '!=', Auth::id()))
            ->orderBy('id')
            ->get();
    }

    /**
     * Resolves the current game's secret answer -- never assigned to a
     * public property (see $answerReveal's own docblock). An ordinary,
     * daily-signed-in, or shared-session game always has an early
     * GameSession row to read `settings.answer` back from (see
     * startNewGame()/startDailySession()/joinChallenge()); the one
     * exception is a guest playing a daily, which gets no early row at all
     * (see mountDaily()) -- its answer is instead recomputed from
     * $dailyDate itself, which is always deterministic and safe to derive
     * server-side on demand.
     */
    private function resolveAnswer(AnswerPicker $answerPicker, DailyPuzzle $dailyPuzzle): string
    {
        if ($this->gameSessionId !== null) {
            return GameSession::findOrFail($this->gameSessionId)->settings['answer'];
        }

        $seed = $dailyPuzzle->seedFor(GameType::Wordle, Carbon::parse($this->dailyDate));

        return $answerPicker->pick($seed)['word'];
    }

    private function finishGame(string $answer): void
    {
        $this->ended = true;
        $this->answerReveal = $answer;
        $this->currentGuess = '';

        $stats = [
            'won' => $this->won,
            'guess_count' => $this->won ? count($this->guesses) : null,
            'hard_mode' => $this->hardMode,
            'guesses' => $this->guesses,
        ];

        // TODO Task 21 (docs/concerns.md, 2026-08-23): "the score should
        // just be how many attempts it took you and the lower score is
        // better," not a derived points total -- guess count itself, with
        // a worse-than-any-win sentinel (one past the max) for a loss, so
        // GameParticipant.score (which every game populates with an
        // integer) still orders every game from best to worst. See
        // docs/DEVLOG.md's Wordle section for how this ripples into
        // WordleStatsService::bestMetrics() and player-stats.blade.php's
        // generic "avg score" framing, both of which otherwise assume
        // higher-is-better.
        $score = $this->won ? count($this->guesses) : self::MAX_GUESSES + 1;

        if ($this->isSharedSession) {
            // Timestamp bookkeeping only -- never overwrite `state` here,
            // see persistState()'s own docblock for why a shared session's
            // state is never written from an individual player's guesses.
            $session = GameSession::find($this->gameSessionId);
            $session->update(['ended_at' => now()]);
        } elseif ($this->gameSessionId !== null) {
            // Ordinary (guest or signed-in) or a signed-in daily -- the
            // early row already exists (see startNewGame()/
            // startDailySession()), update it in place rather than
            // creating a second row.
            $session = GameSession::find($this->gameSessionId);
            $session->update(['state' => $this->progressState(), 'ended_at' => now()]);
        } else {
            // Only reachable for a guest playing a daily -- no early row
            // exists yet (see mountDaily()); create one now, matching
            // Boggle's own guest-daily fallback. Not flagged
            // is_daily/daily_date -- nothing ever needs to look a guest's
            // own row back up, matching the "no lock for guests" reasoning
            // already established for every other daily game in this app.
            $session = GameSession::create([
                'type' => GameType::Wordle,
                'mode' => GameMode::Solo,
                'settings' => ['answer' => $answer, 'hardMode' => $this->hardMode],
                'started_at' => now()->setTimestamp($this->startedAt),
                'ended_at' => now(),
            ]);
            $this->gameSessionId = $session->id;
        }

        Auth::check()
            ? GameParticipant::updateOrCreate(
                ['game_session_id' => $session->id, 'user_id' => Auth::id()],
                ['guest_name' => null, 'score' => $score, 'stats' => $stats],
            )
            : GameParticipant::create([
                'game_session_id' => $session->id,
                'user_id' => null,
                'guest_name' => 'Guest',
                'score' => $score,
                'stats' => $stats,
            ]);
    }

    /**
     * Starts a genuinely fresh ordinary (non-daily, non-shared) game for
     * any player -- guest or signed-in. Unlike Boggle/Word Blocks, this
     * always needs an early row, even for a guest: there's no public seed
     * this game's answer can be regenerated from on demand (deliberately --
     * see docs/specs/wordle.md section 3), so the chosen word has to live
     * somewhere server-side from the very first request. A signed-in
     * user's own already-in-progress row (if resumeInProgressGame() above
     * didn't find one -- e.g. this is New Game, not a fresh mount) is
     * reused in place rather than accumulating a second row; a guest
     * always gets a brand new row, since their identity isn't stable
     * across a reload anyway (matches Boggle/Word Blocks' own reasoning).
     */
    private function startNewGame(AnswerPicker $answerPicker): void
    {
        $word = $answerPicker->pick()['word'];
        $this->resetProgress();
        $this->startedAt = now()->timestamp;

        $attributes = [
            'user_id' => Auth::id(),
            'type' => GameType::Wordle,
            'mode' => GameMode::Solo,
            'is_daily' => false,
            'settings' => ['answer' => $word, 'hardMode' => $this->hardMode],
            'state' => $this->progressState(),
            'started_at' => now()->setTimestamp($this->startedAt),
            'ended_at' => null,
        ];

        $existing = Auth::check() ? $this->findResumableSession() : null;

        if ($existing !== null) {
            $existing->update($attributes);
            $this->gameSessionId = $existing->id;

            return;
        }

        $this->gameSessionId = GameSession::create($attributes)->id;
    }

    /**
     * Hydrates $this from a signed-in user's own saved in-progress ordinary
     * GameSession, if one exists -- see docs/specs/quartiles.md section 4a,
     * the pattern this mirrors. $currentGuess is deliberately never
     * persisted/restored (resuming clears whatever was mid-typed). The
     * answer itself is never restored into a public property here either --
     * resolveAnswer() reads it fresh from the row whenever a guess is
     * actually submitted.
     */
    private function resumeInProgressGame(): bool
    {
        $session = $this->findResumableSession();

        if ($session === null) {
            return false;
        }

        $state = $session->state ?? [];
        $this->guesses = $state['guesses'] ?? [];
        $this->hardModeRequirements = $state['hardModeRequirements'] ?? $this->hardModeRequirements;
        $this->hardMode = $session->settings['hardMode'] ?? false;
        $this->startedAt = $session->started_at->timestamp;
        $this->gameSessionId = $session->id;

        return true;
    }

    private function findResumableSession(): ?GameSession
    {
        return GameSession::query()
            ->where('user_id', Auth::id())
            ->where('type', GameType::Wordle)
            ->where('is_daily', false)
            ->whereNull('ended_at')
            ->first();
    }

    /**
     * Joins an existing GameSession shared via an async challenge link
     * (`route('wordle.share', ...)`) -- the same secret answer, resolved
     * fresh server-side per guess, never regenerated from a public seed
     * (unlike Boggle's own board). If this signed-in user already has a
     * finished GameParticipant on this exact session (e.g. revisiting
     * their own challenge link, or replaying one they already finished),
     * restores that result read-only rather than letting them play a
     * second time -- same guard Boggle's own mount() applies to a shared
     * session.
     */
    private function joinChallenge(int $challengeId): void
    {
        $session = GameSession::where('type', GameType::Wordle)->findOrFail($challengeId);

        // resetProgress() itself clears gameSessionId back to null, so it
        // must run before (not after) this join actually sets it -- a real
        // bug caught by WordleChallengeTest.php: the reversed order left
        // gameSessionId null, which made resolveAnswer() fall through to
        // its guest-daily branch and derive the wrong word entirely.
        $this->resetProgress();
        $this->isSharedSession = true;
        $this->gameSessionId = $session->id;
        $this->hardMode = $session->settings['hardMode'] ?? false;
        $this->startedAt = now()->timestamp;

        if (! Auth::check()) {
            return;
        }

        $participant = GameParticipant::query()
            ->where('game_session_id', $session->id)
            ->where('user_id', Auth::id())
            ->first();

        if ($participant === null) {
            return;
        }

        $this->ended = true;
        $this->won = (bool) ($participant->stats['won'] ?? false);
        $this->guesses = $participant->stats['guesses'] ?? [];
        $this->answerReveal = $session->settings['answer'] ?? null;
    }

    /**
     * Mounts a daily puzzle -- a deterministic answer derived from
     * $dailyDate via DailyPuzzle::seedFor() (see resolveAnswer()), not an
     * arbitrary new pick. Only *today's* date is locked to one signed-in
     * play per day (resumable mid-attempt, restored read-only once
     * finished); any other date -- an archive entry, or any date at all
     * for a guest -- is just an ordinary seeded puzzle with no early row
     * and no lock, freely replayable. A guest gets no row at all here
     * (unlike a signed-in player) since the answer never needs to be
     * looked up from anywhere but $dailyDate itself in that case -- see
     * resolveAnswer().
     *
     * Only restores an *existing* today's session here -- a first-time
     * attempt at today's daily (nothing found), any other date, or a
     * guest all leave $started false and stop here instead, same as a
     * plain new game: startGame() is what actually begins the attempt
     * once Hard Mode is chosen on the setup screen (see its own
     * docblock).
     */
    private function mountDaily(): void
    {
        $date = Carbon::parse($this->dailyDate)->startOfDay();

        if (! $date->isToday() || ! Auth::check()) {
            return;
        }

        $session = $this->findDailySession($date);

        if ($session !== null) {
            $this->restoreDailySession($session);
            $this->started = true;
        }
    }

    private function findDailySession(Carbon $date): ?GameSession
    {
        return GameSession::query()
            ->where('user_id', Auth::id())
            ->where('type', GameType::Wordle)
            ->where('is_daily', true)
            ->where('daily_date', $date->toDateString())
            ->first();
    }

    /**
     * Hydrates $this from today's daily GameSession -- in progress or
     * already finished. A finished row is restored read-only ($ended and
     * $dailyAlreadyDone both true), same shape Word Blocks' own
     * restoreDailySession() uses.
     */
    private function restoreDailySession(GameSession $session): void
    {
        $state = $session->state ?? [];
        $this->guesses = $state['guesses'] ?? [];
        $this->hardModeRequirements = $state['hardModeRequirements'] ?? $this->hardModeRequirements;
        $this->hardMode = $session->settings['hardMode'] ?? false;
        $this->startedAt = $session->started_at->timestamp;
        $this->gameSessionId = $session->id;

        if ($session->ended_at === null) {
            return;
        }

        $this->ended = true;
        $this->dailyAlreadyDone = true;
        $this->won = $state['won'] ?? false;
        $this->answerReveal = $session->settings['answer'] ?? null;
    }

    private function startDailySession(Carbon $date, AnswerPicker $answerPicker, DailyPuzzle $dailyPuzzle): void
    {
        $seed = $dailyPuzzle->seedFor(GameType::Wordle, $date);
        $word = $answerPicker->pick($seed)['word'];

        $this->gameSessionId = GameSession::create([
            'user_id' => Auth::id(),
            'type' => GameType::Wordle,
            'mode' => GameMode::Solo,
            'is_daily' => true,
            'daily_date' => $date->toDateString(),
            'settings' => ['answer' => $word, 'hardMode' => $this->hardMode],
            'state' => $this->progressState(),
            'started_at' => now()->setTimestamp($this->startedAt),
            'ended_at' => null,
        ])->id;
    }

    /**
     * Resets every progress field back to a fresh-game state -- called by
     * startNewGame()/mountDaily()/joinChallenge() before they populate
     * their own starting state. $hardMode itself is deliberately left
     * untouched (a player's own preference persists across New Game),
     * unlike everything else here.
     */
    private function resetProgress(): void
    {
        $this->guesses = [];
        $this->hardModeRequirements = (new GuessEvaluator)->emptyHardModeRequirements(self::WORD_LENGTH);
        $this->currentGuess = '';
        $this->selectedCol = null;
        $this->ended = false;
        $this->won = false;
        $this->answerReveal = null;
        $this->message = null;
        $this->gameSessionId = null;
    }

    /**
     * @return array{guesses: list<array{word: string, result: list<string>}>, hardModeRequirements: array{positions: array<int, ?string>, minCounts: array<string, int>}, won: bool}
     */
    private function progressState(): array
    {
        return [
            'guesses' => $this->guesses,
            'hardModeRequirements' => $this->hardModeRequirements,
            'won' => $this->won,
        ];
    }

    /**
     * Saves the current progress to the already-started session from
     * startNewGame()/startDailySession() -- called after every submitted
     * guess that doesn't end the game. A no-op if no row exists yet (a
     * guest mid-daily -- see mountDaily()) or for a shared/challenge
     * session: unlike an ordinary or daily row (exclusively written by one
     * identity), a shared session's single `state` column would otherwise
     * be a race between every player who's ever joined it via $challenge,
     * each independently overwriting the others' in-progress guesses with
     * their own. Each player's own guesses live in their own
     * GameParticipant.stats instead (see finishGame()), never in the
     * shared session's state.
     */
    private function persistState(): void
    {
        if ($this->gameSessionId === null || $this->isSharedSession) {
            return;
        }

        GameSession::whereKey($this->gameSessionId)->update(['state' => $this->progressState()]);
    }
};
?>

<x-slot:title>Five Letters — Low Stim Play</x-slot>
<x-slot:description>Play Five Letters online — guess the secret 5-letter word in 6 tries, calm low-contrast visuals with no flashing or motion.</x-slot:description>
<x-slot:bodyClass>wordle-play</x-slot:bodyClass>
<x-slot:rulesTitle>Five Letters Rules</x-slot:rulesTitle>
<x-slot:rules>
    <h3>How to Use This Page</h3>
    <ul>
        <li>Type a 5-letter word (or tap the on-screen keyboard) and press Enter/Submit to guess.</li>
        <li>Tap a tile in the current row to fix a single letter instead of retyping the whole word.</li>
        <li>Each letter is marked correct (right spot), present (in the word, wrong spot), or absent — a legend above the grid shows exactly what each color/mark means.</li>
        <li>You have 6 guesses to find the secret word.</li>
        <li>If you're signed in, leaving and coming back later picks up right where you left off.</li>
    </ul>

    <h3>Full Rules</h3>
    <ul>
        <li>Guesses must be real 5-letter words, checked against the same word list Word Grid uses.</li>
        <li>Hard mode (chosen on the setup screen before you start): every later guess must reuse each letter already revealed correct or present.</li>
        <li>The on-screen keyboard recolors each letter to the best status it's earned across all your guesses so far.</li>
    </ul>
</x-slot:rules>

<div class="flex flex-col items-center w-full gap-6" style="max-width: 480px;">
    @teleport('#game-actions')
        <div style="display: contents;">
            @if ($ended)
                @if ($isDaily)
                    <a href="{{ route('daily-puzzles') }}"><button type="button" id="new-game-btn">More Daily Puzzles</button></a>
                @elseif (! $isSharedSession)
                    <button type="button" id="new-game-btn" wire:click="resetGame">New Game</button>
                @endif
            @endif
        </div>
    @endteleport

    {{--
        Shown regardless of $started -- a player choosing Hard Mode on the
        setup screen below should already know this is today's daily, not
        an ordinary game, before they commit to starting it.
    --}}
    @if ($isDaily)
        <p id="daily-badge" class="text-xs text-center text-accent font-bold">
            Daily Puzzle — {{ Carbon::parse($dailyDate)->format('M j, Y') }}
        </p>
    @endif

    @unless ($started)
        {{--
            Hard Mode used to be an in-game toggle, disabled after the
            first guess -- confusing, since "locked but still visible"
            reads as broken rather than intentional (docs/concerns.md).
            Moved to its own pre-game screen instead, the same
            `.setup`/`.setup-row`/`.option-group` shape Yahtzee's own
            player-count/Undo setup already established: nothing about
            this playthrough exists yet (no GameSession row, no word
            picked) until startGame() runs.
        --}}
        <div class="setup" id="setup">
            <div class="heading">Five Letters</div>

            <div class="setup-row">
                <div class="label">Hard Mode</div>
                <div class="option-group">
                    <button type="button" id="hard-mode-off" class="option {{ ! $hardMode ? 'active' : '' }}" wire:click="$set('hardMode', false)">Off</button>
                    <button type="button" id="hard-mode-on" class="option {{ $hardMode ? 'active' : '' }}" wire:click="$set('hardMode', true)">On</button>
                </div>
            </div>

            <button type="button" id="start-game-btn" wire:click="startGame">Start Game</button>
        </div>
    @else
        {{--
            Explains what the color + corner mark on a guessed tile actually
            means -- previously only described in prose inside the Rules
            dialog, easy to miss entirely for a first-time player. Reuses
            tileClasses()/tileMark() directly (same source the real grid/
            keyboard render from below), so this can never silently drift out
            of sync with what a real guessed tile actually looks like.
        --}}
        <div class="flex flex-wrap items-center justify-center gap-x-4 gap-y-1 text-xs text-text/80" id="wordle-legend">
            @foreach (['correct' => 'Correct spot', 'present' => 'Wrong spot', 'absent' => 'Not in word'] as $legendStatus => $legendLabel)
                <span class="flex items-center gap-1.5" wire:key="legend-{{ $legendStatus }}">
                    <span class="relative flex items-center justify-center rounded border-2 text-[0.65rem] font-bold uppercase w-5 h-5 {{ $this->tileClasses($legendStatus) }}">
                        A
                        @if ($this->tileMark($legendStatus) !== '')
                            <span class="absolute -top-0.5 -right-0.5 text-[0.5rem] leading-none" aria-hidden="true">{{ $this->tileMark($legendStatus) }}</span>
                        @endif
                    </span>
                    {{ $legendLabel }}
                </span>
            @endforeach
        </div>

        <div class="flex flex-col items-center gap-1" id="wordle-grid">
            @for ($row = 0; $row < self::MAX_GUESSES; $row++)
                <div class="grid grid-cols-5 gap-1.5" wire:key="row-{{ $row }}">
                    @for ($col = 0; $col < self::WORD_LENGTH; $col++)
                        @php
                            $isCurrentRow = $row === count($guesses);
                            $guessRow = $guesses[$row] ?? null;
                            $letter = $guessRow['word'][$col] ?? ($isCurrentRow ? ($currentGuess[$col] ?? '') : '');
                            $status = $guessRow['result'][$col] ?? null;
                            $mark = $this->tileMark($status);
                            $isSelected = $isCurrentRow && $col === $selectedCol;
                        @endphp
                        <div id="cell-{{ $row }}-{{ $col }}" wire:key="cell-{{ $row }}-{{ $col }}"
                            @if ($isCurrentRow && ! $ended) wire:click="selectCell({{ $col }})" @endif
                            class="relative aspect-square w-14 flex items-center justify-center rounded-lg border-2 text-xl font-bold uppercase {{ $this->tileClasses($status) }} {{ $isCurrentRow && ! $ended ? 'cursor-pointer' : '' }} {{ $isSelected ? 'ring-2 ring-accent' : '' }}">
                            {{ $letter }}
                            @if ($mark !== '')
                                <span class="absolute top-0.5 right-1 text-[0.6rem] leading-none" aria-hidden="true">{{ $mark }}</span>
                            @endif
                        </div>
                    @endfor
                </div>
            @endfor
        </div>

        @if ($message)
            <p class="status-line" style="color: var(--warn);" id="wordle-message">{{ $message }}</p>
        @endif

        @unless ($ended)
            <div class="flex flex-col items-center gap-3 w-full">
                <form class="flex gap-2 w-full justify-center" wire:submit="submitGuess">
                    <input type="text" id="wordle-input" wire:model="currentGuess"
                        maxlength="{{ self::WORD_LENGTH }}" autocomplete="off" autocapitalize="off" spellcheck="false"
                        class="text-center uppercase tracking-widest" style="width: 10rem;">
                    <button type="submit" id="wordle-submit-btn">Submit</button>
                </form>

                {{--
                    A read-only-status, clickable QWERTY keyboard -- clicking a
                    letter appends to $currentGuess (same as typing it), and
                    each key recolors to its best-known status across every
                    guess so far (getKeyboardStatusProperty()). Pairing color
                    with the small mark below (not shown on the keyboard itself,
                    since a full-size mark per tiny key would be unreadable) is
                    covered by the main grid above -- this row's job is glanceable
                    recoloring, not the primary correctness signal.
                --}}
                <div class="flex flex-col items-center gap-1.5 w-full" id="wordle-keyboard">
                    @foreach (['qwertyuiop', 'asdfghjkl', 'zxcvbnm'] as $rowLetters)
                        <div class="flex gap-1 justify-center">
                            @foreach (str_split($rowLetters) as $letter)
                                @php $keyStatus = $this->keyboardStatus[$letter] ?? null; @endphp
                                <button type="button" id="key-{{ $letter }}" wire:key="key-{{ $letter }}" wire:click="pressKey('{{ $letter }}')"
                                    class="rounded border-2 text-xs font-bold uppercase px-2 py-2.5 {{ $this->tileClasses($keyStatus) }}">
                                    {{ $letter }}
                                </button>
                            @endforeach
                        </div>
                    @endforeach
                    <button type="button" id="wordle-backspace-btn" wire:click="backspace" class="text-xs px-3 py-2">Backspace</button>
                </div>
            </div>
        @else
            <div class="flex flex-col items-center gap-4 w-full">
                <p class="status-line text-center" id="wordle-result">
                    @if ($won)
                        Solved in {{ count($guesses) }} {{ count($guesses) === 1 ? 'guess' : 'guesses' }}!
                    @else
                        The word was {{ strtoupper((string) $answerReveal) }}.
                    @endif
                </p>

                @if ($isSharedSession && $this->otherParticipants->isNotEmpty())
                    <div class="flex flex-col gap-2 w-full">
                        <p class="text-sm text-text/80 font-bold">Others who've played this challenge</p>
                        <div class="flex flex-wrap justify-center gap-2 w-full">
                            @foreach ($this->otherParticipants as $participant)
                                <span wire:key="other-{{ $participant->id }}" class="text-sm bg-surface border border-surface-border rounded px-3 py-1.5 text-text">
                                    {{ $participant->user->name ?? $participant->guest_name }} —
                                    {{ ($participant->stats['won'] ?? false) ? ($participant->stats['guess_count'].' guesses') : 'did not solve it' }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($isDaily && $dailyDate === now()->toDateString())
                    {{-- Covers both a just-finished today's daily (this same
                         request) and a restored, already-done one
                         ($dailyAlreadyDone) -- either way there's nothing left
                         to play until tomorrow. --}}
                    <x-daily-countdown :resets-at="now()->addDay()->startOfDay()->timestamp" />
                @elseif (! $isDaily && ! $isSharedSession && $gameSessionId !== null)
                    @auth
                        <livewire:challenge-friend :game-type="GameType::Wordle"
                            :link="route('wordle.share', ['gameSession' => $gameSessionId])"
                            wire:key="challenge-friend-wordle-{{ $gameSessionId }}" />
                    @endauth
                @endif
            </div>
        @endif
    @endif
</div>
