<?php

use App\Enums\GameMode;
use App\Enums\GameType;
use App\Enums\PipsDifficulty;
use App\Models\GameParticipant;
use App\Models\GameSession;
use App\Services\Pips\ConstraintChecker;
use App\Services\Pips\ConstraintStatus;
use App\Services\Pips\PuzzleGenerator;
use App\Services\Pips\Scorer;
use App\Services\Puzzles\DailyPuzzle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    /**
     * A public puzzle is identified by seed+difficulty together (see
     * PipsPuzzle's own docblock) -- sharing both makes an identical puzzle
     * reproducible, same purpose as Word Blocks/Word Hive's own `#[Url]
     * $seed`, since nothing about a Pips board is secret either
     * (docs/specs/pips.md section 1).
     */
    #[Url]
    public ?int $seed = null;

    /**
     * `easy`/`medium`/`hard` -- when null on a bare visit, mount() treats it
     * like Word Hive treats a null `$seed`: try to resume any in-progress
     * game first. When explicitly present (every homepage/daily link always
     * includes it), it's an explicit "start fresh at this tier" request
     * that skips resume, the same way an explicit `$seed` already does.
     */
    #[Url]
    public ?string $difficulty = null;

    /**
     * A `Y-m-d` date string -- mirrors pages::spelling-bee.play's own
     * property of the same name exactly, except a daily Pips puzzle also
     * needs $difficulty to pick which of the day's three tiers to load.
     */
    #[Url]
    public ?string $dailyDate = null;

    /**
     * True once mountDaily() has restored *today's* already-finished
     * attempt at the resolved difficulty -- see pages::quartiles.play's own
     * property of the same name.
     */
    public bool $dailyAlreadyDone = false;

    /**
     * Regions/constraints/dimensions, loaded from the puzzle row -- static
     * for the whole game once loaded, never mutated after mount()/
     * resetGame(). Never includes the puzzle's `solution` (see PipsPuzzle's
     * own docblock).
     *
     * @var array{dimensions: array{rows: int, cols: int}, cells: list<int>, regions: list<array{id: int, cells: list<int>, constraint: array{type: string, value: int|null}, color: int, badge: ?array{row: float, col: float}}>}
     */
    public array $board = [];

    /**
     * cellIndex => {value, dominoId}, only for cells actually covered so
     * far.
     *
     * @var array<int, array{value: int, dominoId: string}>
     */
    public array $placements = [];

    /**
     * Domino ids ("a-b") not yet placed on the board.
     *
     * @var list<string>
     */
    public array $trayRemaining = [];

    /**
     * Tap-to-select state (docs/specs/pips.md section 3) -- the tray
     * domino currently selected, awaiting a first/second cell tap.
     */
    public ?string $selectedDominoId = null;

    /**
     * Set once, on the very first successful placement of the session, and
     * never cleared by a later removePlacement() -- the spec's "elapsed
     * time measured from the first domino placement" is literal: undoing
     * everything afterward doesn't restart the clock.
     */
    public ?int $firstPlacementAt = null;

    public ?string $message = null;

    /**
     * The seed/difficulty the current puzzle was actually generated with --
     * see pages::quartiles.play's own $resolvedSeed.
     */
    public int $resolvedSeed = 0;

    public string $resolvedDifficulty = 'medium';

    public int $startedAt;

    public bool $ended = false;

    public bool $solved = false;

    public ?int $gameSessionId = null;

    public function mount(PuzzleGenerator $generator, DailyPuzzle $dailyPuzzle): void
    {
        if ($this->dailyDate !== null) {
            $this->mountDaily($generator, $dailyPuzzle);

            return;
        }

        if ($this->seed === null && $this->difficulty === null && Auth::check() && $this->resumeInProgressGame()) {
            return;
        }

        $this->loadNewPuzzle($generator, $this->seed, $this->resolveDifficulty($this->difficulty));
        $this->startNewSession();
    }

    /**
     * Tap-to-select's first step (docs/specs/pips.md section 3) -- tapping
     * an already-selected domino deselects it.
     */
    public function selectTrayDomino(?string $dominoId): void
    {
        if ($this->ended) {
            return;
        }

        if ($dominoId !== null && ! in_array($dominoId, $this->trayRemaining, true)) {
            return;
        }

        $this->selectedDominoId = $this->selectedDominoId === $dominoId ? null : $dominoId;
    }

    /**
     * The single source of truth both the drag-and-drop and tap-to-select
     * interaction paths call (docs/specs/pips.md section 3) -- validated
     * server-side regardless of which client path (or a test) called it,
     * never trusting client-supplied adjacency/occupancy.
     */
    public function placeDomino(string $dominoId, int $cellA, int $cellB, bool $flip = false): void
    {
        if ($this->ended || ! in_array($dominoId, $this->trayRemaining, true)) {
            return;
        }

        if (! $this->areAdjacentCells($cellA, $cellB)) {
            $this->message = 'Dominoes can only be placed on adjacent cells.';

            return;
        }

        if (isset($this->placements[$cellA]) || isset($this->placements[$cellB])) {
            $this->message = 'That cell is already covered.';

            return;
        }

        [$lo, $hi] = array_map('intval', explode('-', $dominoId));
        $valueA = $flip ? $hi : $lo;
        $valueB = $flip ? $lo : $hi;

        $this->firstPlacementAt ??= now()->timestamp;
        $this->placements[$cellA] = ['value' => $valueA, 'dominoId' => $dominoId];
        $this->placements[$cellB] = ['value' => $valueB, 'dominoId' => $dominoId];
        $this->trayRemaining = array_values(array_diff($this->trayRemaining, [$dominoId]));
        $this->selectedDominoId = null;
        $this->message = null;

        $this->persistState();

        if ($this->isBoardSolved()) {
            $this->endGame(solved: true);
        }
    }

    /**
     * Picks a placed domino back up -- both a board drag "move" and a tap
     * "pick up to replace" funnel through this before a fresh
     * placeDomino() call.
     */
    public function removePlacement(string $dominoId): void
    {
        if ($this->ended) {
            return;
        }

        $cells = array_keys(array_filter(
            $this->placements,
            fn (array $placement) => $placement['dominoId'] === $dominoId,
        ));

        if ($cells === []) {
            return;
        }

        foreach ($cells as $cell) {
            unset($this->placements[$cell]);
        }

        if (! in_array($dominoId, $this->trayRemaining, true)) {
            $this->trayRemaining[] = $dominoId;
        }

        $this->message = null;
        $this->persistState();
    }

    /**
     * Rotates a placed domino in place -- swaps which of its two cells
     * holds which value, footprint unchanged (always valid, so no
     * rejection path is needed beyond "is this actually a placed
     * domino"). A plain tap (not a drag) on a placed domino calls this now
     * instead of removePlacement() -- relocating happens via drag instead
     * (movePlacement() below).
     */
    public function rotatePlacement(string $dominoId): void
    {
        if ($this->ended) {
            return;
        }

        $cells = array_keys(array_filter(
            $this->placements,
            fn (array $placement) => $placement['dominoId'] === $dominoId,
        ));

        if (count($cells) !== 2) {
            return;
        }

        [$cellA, $cellB] = $cells;
        $valueA = $this->placements[$cellA]['value'];
        $this->placements[$cellA]['value'] = $this->placements[$cellB]['value'];
        $this->placements[$cellB]['value'] = $valueA;

        $this->persistState();

        if ($this->isBoardSolved()) {
            $this->endGame(solved: true);
        }
    }

    /**
     * Relocates an already-placed domino to a new cell pair in one atomic
     * round trip -- validated before anything is mutated, so a rejected
     * move never loses the original placement. The two cells this domino
     * already occupies are implicitly available targets too (a player can
     * drop it back overlapping where it started) since $targetCells is
     * diffed against $oldCells before the occupancy check, not blocked by
     * the domino's own current placement.
     */
    public function movePlacement(string $dominoId, int $newCellA, int $newCellB, bool $flip): void
    {
        if ($this->ended) {
            return;
        }

        $oldCells = array_keys(array_filter(
            $this->placements,
            fn (array $placement) => $placement['dominoId'] === $dominoId,
        ));

        if (count($oldCells) !== 2) {
            return;
        }

        if (! $this->areAdjacentCells($newCellA, $newCellB)) {
            $this->message = 'Dominoes can only be placed on adjacent cells.';

            return;
        }

        foreach (array_diff([$newCellA, $newCellB], $oldCells) as $cell) {
            if (isset($this->placements[$cell])) {
                $this->message = 'That cell is already covered.';

                return;
            }
        }

        foreach ($oldCells as $cell) {
            unset($this->placements[$cell]);
        }

        [$lo, $hi] = array_map('intval', explode('-', $dominoId));
        $this->placements[$newCellA] = ['value' => $flip ? $hi : $lo, 'dominoId' => $dominoId];
        $this->placements[$newCellB] = ['value' => $flip ? $lo : $hi, 'dominoId' => $dominoId];
        $this->message = null;

        $this->persistState();

        if ($this->isBoardSolved()) {
            $this->endGame(solved: true);
        }
    }

    /**
     * Explicit manual end without solving -- matches Wordle's own loss
     * treatment (score 0).
     */
    public function giveUp(): void
    {
        if ($this->ended) {
            return;
        }

        $this->endGame(solved: false);
    }

    public function resetGame(PuzzleGenerator $generator): void
    {
        $this->loadNewPuzzle($generator, null, PipsDifficulty::from($this->resolvedDifficulty));
        $this->startNewSession();
    }

    /**
     * Live per-region satisfied/broken/unknown status, keyed by region id
     * -- drives the board's per-region visual feedback (docs/specs/pips.md
     * section 4/5, a shape/icon change, not color-only).
     *
     * A "Satisfied" verdict is only ever shown once a region is completely
     * filled, even though ConstraintChecker itself can resolve a loose
     * greater/less constraint as satisfied far earlier (e.g. a region whose
     * maximum possible total already stays under a "less than" threshold
     * with zero cells filled) -- true early, but confusing to show as
     * "correct" on a region the player hasn't touched at all, so it's
     * downgraded back to Unknown here until $remaining reaches 0. Broken
     * still surfaces as early as ConstraintChecker itself allows -- only
     * the "looks done" signal needed gating, not the "already broken" one.
     *
     * @return array<int, string>
     */
    public function getRegionStatusProperty(): array
    {
        $checker = app(ConstraintChecker::class);
        $maxPip = PipsDifficulty::from($this->resolvedDifficulty)->maxPip();
        $status = [];

        foreach ($this->board['regions'] ?? [] as $region) {
            [$filled, $remaining] = $this->regionFillState($region['cells']);
            $checked = $checker->check($region['constraint'], $filled, $remaining, $maxPip);

            $status[$region['id']] = ($checked === ConstraintStatus::Satisfied && $remaining > 0)
                ? ConstraintStatus::Unknown->name
                : $checked->name;
        }

        return $status;
    }

    private function resolveDifficulty(?string $value): PipsDifficulty
    {
        return PipsDifficulty::tryFrom($value ?? '') ?? PipsDifficulty::Medium;
    }

    private function areAdjacentCells(int $cellA, int $cellB): bool
    {
        $cols = $this->board['dimensions']['cols'];
        $rowA = intdiv($cellA, $cols);
        $colA = $cellA % $cols;
        $rowB = intdiv($cellB, $cols);
        $colB = $cellB % $cols;

        return abs($rowA - $rowB) + abs($colA - $colB) === 1;
    }

    /**
     * The glyph shown on a region's on-board badge -- 'none' regions never
     * get a badge at all (see the Blade section below), so this is never
     * called for that type.
     *
     * @param  array{type: string, value: int|null}  $constraint
     */
    private function badgeGlyphFor(array $constraint): string
    {
        return match ($constraint['type']) {
            'sum' => (string) $constraint['value'],
            'equal' => '=',
            'different' => '≠',
            'greater' => '>'.$constraint['value'],
            'less' => '<'.$constraint['value'],
            default => '',
        };
    }

    /**
     * @param  list<int>  $cells
     * @return array{0: list<int>, 1: int}
     */
    private function regionFillState(array $cells): array
    {
        $filled = [];
        $remaining = 0;

        foreach ($cells as $cell) {
            if (isset($this->placements[$cell])) {
                $filled[] = $this->placements[$cell]['value'];
            } else {
                $remaining++;
            }
        }

        return [$filled, $remaining];
    }

    private function isBoardSolved(): bool
    {
        if (count($this->placements) !== count($this->board['cells'])) {
            return false;
        }

        foreach ($this->getRegionStatusProperty() as $status) {
            if ($status !== ConstraintStatus::Satisfied->name) {
                return false;
            }
        }

        return true;
    }

    private function endGame(bool $solved): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;
        $this->solved = $solved;

        $duration = $this->firstPlacementAt !== null ? now()->timestamp - $this->firstPlacementAt : null;
        $score = ($solved && $duration !== null) ? app(Scorer::class)->scoreFor($duration) : 0;

        $stats = [
            'solved' => $solved,
            'duration_seconds' => $duration,
            'difficulty' => $this->resolvedDifficulty,
        ];

        if (Auth::check() && $this->gameSessionId !== null) {
            $session = GameSession::find($this->gameSessionId);
            $session->update(['state' => $this->progressState(), 'ended_at' => now()]);
        } else {
            $session = GameSession::create([
                'type' => GameType::Pips,
                'mode' => GameMode::Solo,
                'settings' => ['seed' => $this->resolvedSeed, 'difficulty' => $this->resolvedDifficulty],
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

    private function loadNewPuzzle(PuzzleGenerator $generator, ?int $seed, PipsDifficulty $difficulty): void
    {
        $seed ??= random_int(0, PHP_INT_MAX);
        $puzzle = $generator->forSeed($seed, $difficulty);

        $this->board = $puzzle->board;
        $this->resolvedSeed = $puzzle->seed;
        $this->resolvedDifficulty = $puzzle->difficulty->value;
        $this->resetProgress();
    }

    private function resetProgress(): void
    {
        $this->placements = [];
        $this->trayRemaining = $this->allDominoIds();
        $this->selectedDominoId = null;
        $this->firstPlacementAt = null;
        $this->message = null;
        $this->ended = false;
        $this->solved = false;
        $this->startedAt = now()->timestamp;
        $this->gameSessionId = null;
    }

    /**
     * @return list<string>
     */
    private function allDominoIds(): array
    {
        $maxPip = PipsDifficulty::from($this->resolvedDifficulty)->maxPip();
        $ids = [];

        for ($a = 0; $a <= $maxPip; $a++) {
            for ($b = $a; $b <= $maxPip; $b++) {
                $ids[] = "{$a}-{$b}";
            }
        }

        return $ids;
    }

    /**
     * @return array{board: array, placements: array<int, array{value: int, dominoId: string}>, trayRemaining: list<string>, firstPlacementAt: ?int, resolvedSeed: int, resolvedDifficulty: string, solved: bool}
     */
    private function progressState(): array
    {
        return [
            'board' => $this->board,
            'placements' => $this->placements,
            'trayRemaining' => $this->trayRemaining,
            'firstPlacementAt' => $this->firstPlacementAt,
            'resolvedSeed' => $this->resolvedSeed,
            'resolvedDifficulty' => $this->resolvedDifficulty,
            'solved' => $this->solved,
        ];
    }

    /**
     * Saves progress after every placement/removal -- called from
     * placeDomino()/removePlacement() only, same as Word Hive's own
     * persistState() being called only after a scoring-relevant mutation. A
     * no-op for guests.
     */
    private function persistState(): void
    {
        if (! Auth::check() || $this->gameSessionId === null) {
            return;
        }

        GameSession::whereKey($this->gameSessionId)->update(['state' => $this->progressState()]);
    }

    private function hydrateFromState(GameSession $session): void
    {
        $state = $session->state ?? [];

        $this->board = $state['board'] ?? [];
        $this->placements = $state['placements'] ?? [];
        $this->trayRemaining = $state['trayRemaining'] ?? [];
        $this->firstPlacementAt = $state['firstPlacementAt'] ?? null;
        $this->resolvedSeed = $state['resolvedSeed'] ?? ($session->settings['seed'] ?? 0);
        $this->resolvedDifficulty = $state['resolvedDifficulty'] ?? ($session->settings['difficulty'] ?? PipsDifficulty::Medium->value);
        $this->solved = $state['solved'] ?? false;
        $this->startedAt = $session->started_at->timestamp;
        $this->gameSessionId = $session->id;
    }

    private function resumeInProgressGame(): bool
    {
        $session = GameSession::query()
            ->where('user_id', Auth::id())
            ->where('type', GameType::Pips)
            ->where('is_daily', false)
            ->whereNull('ended_at')
            ->first();

        if ($session === null) {
            return false;
        }

        $this->hydrateFromState($session);

        return true;
    }

    private function startNewSession(): void
    {
        if (! Auth::check()) {
            return;
        }

        $attributes = [
            'user_id' => Auth::id(),
            'type' => GameType::Pips,
            'mode' => GameMode::Solo,
            'is_daily' => false,
            'settings' => ['seed' => $this->resolvedSeed, 'difficulty' => $this->resolvedDifficulty],
            'state' => $this->progressState(),
            'started_at' => now()->setTimestamp($this->startedAt),
            'ended_at' => null,
        ];

        $existing = GameSession::query()
            ->where('user_id', Auth::id())
            ->where('type', GameType::Pips)
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
        $difficulty = $this->resolveDifficulty($this->difficulty);
        $date = Carbon::parse($this->dailyDate)->startOfDay();
        $seed = $dailyPuzzle->seedFor(GameType::Pips, $date, $difficulty->value);

        if ($date->isToday() && Auth::check()) {
            $session = $this->findDailySession($date, $difficulty);

            if ($session !== null) {
                $this->restoreDailySession($session);

                return;
            }

            $this->loadNewPuzzle($generator, $seed, $difficulty);
            $this->startDailySession($date);

            return;
        }

        $this->loadNewPuzzle($generator, $seed, $difficulty);
    }

    /**
     * Filtered in PHP rather than a `settings->difficulty` JSON-path where
     * clause -- a user has at most 3 daily Pips sessions for any one date
     * (one per tier), so fetching that small set and filtering here avoids
     * relying on JSON-path query support across DB drivers for a query this
     * cheap either way.
     */
    private function findDailySession(Carbon $date, PipsDifficulty $difficulty): ?GameSession
    {
        return GameSession::query()
            ->where('user_id', Auth::id())
            ->where('type', GameType::Pips)
            ->where('is_daily', true)
            ->where('daily_date', $date->toDateString())
            ->get()
            ->first(fn (GameSession $session) => ($session->settings['difficulty'] ?? null) === $difficulty->value);
    }

    private function startDailySession(Carbon $date): void
    {
        $this->gameSessionId = GameSession::create([
            'user_id' => Auth::id(),
            'type' => GameType::Pips,
            'mode' => GameMode::Solo,
            'is_daily' => true,
            'daily_date' => $date->toDateString(),
            'settings' => ['seed' => $this->resolvedSeed, 'difficulty' => $this->resolvedDifficulty],
            'state' => $this->progressState(),
            'started_at' => now()->setTimestamp($this->startedAt),
            'ended_at' => null,
        ])->id;
    }

    private function restoreDailySession(GameSession $session): void
    {
        $this->hydrateFromState($session);

        if ($session->ended_at === null) {
            return;
        }

        $this->ended = true;
        $this->dailyAlreadyDone = true;
    }
};
?>

<x-slot:title>Pips — Low Stim Play</x-slot:title>
<x-slot:description>Play Pips online — place every domino from the tray onto the board so every outlined region's rule is satisfied, calm low-contrast visuals with no flashing or motion.</x-slot:description>
<x-slot:bodyClass>pips-play</x-slot:bodyClass>
<x-slot:rulesTitle>Pips Rules</x-slot:rulesTitle>
<x-slot:rules>
    <h3>How to Use This Page</h3>
    <ul>
        <li>Drag a domino from the tray onto the board, or tap a tray domino then tap two adjacent cells, to place it.</li>
        <li>Tap an already-selected tray domino again to rotate it 90° before placing it; keep tapping to cycle through all four orientations, then deselect it.</li>
        <li>Tap a placed domino to rotate it in place; drag a placed domino to move it elsewhere on the board.</li>
        <li>Every outlined region shows a rule -- an exact total, "all equal," "all different," a greater/less-than threshold, or no rule at all -- that its own cells must satisfy once filled.</li>
        <li>Once every cell in a region is filled, its outline turns solid with a checkmark if the rule holds, or tints with an X if it doesn't.</li>
        <li>The board is solved once every cell is covered and every region's rule holds.</li>
        <li>If you're signed in, leaving and coming back later picks up right where you left off.</li>
    </ul>

    <h3>Full Rules</h3>
    <ul>
        <li>Every domino must be used exactly once, covering two adjacent empty cells.</li>
        <li>A domino can straddle two different regions at once -- each of its two ends counts only toward its own region's rule.</li>
        <li>Scoring rewards a faster solve; giving up before finishing scores 0.</li>
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
                <button type="button" id="give-up-btn" wire:click="giveUp">Give Up</button>
            @endif
        </div>
    @endteleport

    @if ($dailyDate !== null)
        <p id="daily-badge" class="text-xs text-center text-accent font-bold">
            Daily Puzzle — {{ Carbon::parse($dailyDate)->format('M j, Y') }}
        </p>
    @endif

    <p id="pips-difficulty-badge" class="text-xs text-center text-text/65">
        Difficulty: {{ ucfirst($resolvedDifficulty) }}
    </p>

    @unless ($ended)
        @php
            $cols = $board['dimensions']['cols'];
            $rows = $board['dimensions']['rows'];
            $cellRegion = [];
            foreach ($board['regions'] as $region) {
                foreach ($region['cells'] as $cell) {
                    $cellRegion[$cell] = $region;
                }
            }
        @endphp
        <div class="flex flex-col items-center gap-4 w-full max-w-2xl" id="pips-board-wrap"
            data-rows="{{ $rows }}" data-cols="{{ $cols }}">
            <div class="pips-board" id="pips-board" style="--pips-rows: {{ $rows }}; --pips-cols: {{ $cols }};">
                @foreach ($board['cells'] as $cell)
                    @php
                        $region = $cellRegion[$cell];
                        $status = $this->regionStatus[$region['id']] ?? 'Unknown';
                        $filled = $placements[$cell] ?? null;
                        $cellRow = intdiv($cell, $cols);
                        $cellCol = $cell % $cols;
                    @endphp
                    <button type="button"
                        id="pips-cell-{{ $cell }}"
                        data-cell="{{ $cell }}"
                        data-region="{{ $region['id'] }}"
                        data-filled="{{ $filled !== null ? '1' : '0' }}"
                        data-domino-id="{{ $filled['dominoId'] ?? '' }}"
                        data-value="{{ $filled['value'] ?? '' }}"
                        class="pips-cell pips-status-{{ strtolower($status) }} flex items-center justify-center border-2 rounded-md"
                        style="grid-column: {{ $cellCol + 1 }}; grid-row: {{ $cellRow + 1 }}; --pips-region-color: var(--region-{{ $region['color'] + 1 }});"
                    >
                        @if ($filled !== null)
                            <x-pips-pip :value="$filled['value']" />
                        @endif
                    </button>
                @endforeach

                @foreach ($board['regions'] as $region)
                    @continue ($region['badge'] === null)
                    <div class="pips-badge"
                        style="--pips-badge-row: {{ $region['badge']['row'] }}; --pips-badge-col: {{ $region['badge']['col'] }}; --pips-region-color: var(--region-{{ $region['color'] + 1 }});"
                    >
                        <span>{{ $this->badgeGlyphFor($region['constraint']) }}</span>
                    </div>
                @endforeach
            </div>

            <div class="flex flex-wrap justify-center gap-2 w-full" id="pips-tray">
                @foreach ($trayRemaining as $dominoId)
                    @php [$a, $b] = explode('-', $dominoId); @endphp
                    <button type="button"
                        id="pips-tray-{{ $dominoId }}"
                        data-domino-id="{{ $dominoId }}"
                        class="pips-domino flex items-center border-2 rounded-md p-1 {{ $selectedDominoId === $dominoId ? 'pips-domino-selected' : '' }}"
                    >
                        <x-pips-pip :value="(int) $a" />
                        <div class="pips-domino-divider"></div>
                        <x-pips-pip :value="(int) $b" />
                    </button>
                @endforeach
            </div>

            @if ($message)
                <p class="status-line" style="color: var(--warn);">{{ $message }}</p>
            @endif
        </div>
    @else
        <div class="flex flex-col items-center gap-4 w-full max-w-md">
            <p id="pips-result" class="text-lg font-bold text-text">
                {{ $solved ? 'Solved!' : 'Board not completed.' }}
            </p>

            @if ($dailyDate === now()->toDateString())
                <x-daily-countdown :resets-at="now()->addDay()->startOfDay()->timestamp" />
            @elseif ($dailyDate === null)
                <div class="flex gap-2 w-full">
                    <input type="text" readonly value="{{ route('pips.play', ['seed' => $resolvedSeed, 'difficulty' => $resolvedDifficulty]) }}"
                        onclick="this.select()" style="flex: 1;">
                    <button type="button" data-copy="{{ route('pips.play', ['seed' => $resolvedSeed, 'difficulty' => $resolvedDifficulty]) }}">
                        Copy
                    </button>
                </div>

                @auth
                    <livewire:challenge-friend :game-type="GameType::Pips"
                        :link="route('pips.play', ['seed' => $resolvedSeed, 'difficulty' => $resolvedDifficulty])"
                        wire:key="challenge-friend-pips-{{ $resolvedSeed }}-{{ $resolvedDifficulty }}" />
                @endauth
            @endif
        </div>
    @endif
</div>

@script
<script>
    // Vanilla pointer events, not HTML5 drag-and-drop or an Alpine state
    // machine -- same approach as pages::boggle.play's own drag gesture
    // (see its own script block, at the bottom of that file), with
    // client-only gesture state and exactly
    // one $wire round trip per placement, never mid-gesture (a mid-drag
    // round trip would let Livewire's DOM morph clobber the geometry this
    // script is caching). Tap-to-place on two board cells keeps its own
    // orientation convention (whichever cell is tapped first gets the
    // domino's lower pip); dragging instead uses closestValidPair() below
    // plus each domino's own client-only flip state, since a drag has no
    // equivalent "which cell first" signal of its own.
    const boardEl = document.getElementById('pips-board');
    const trayEl = document.getElementById('pips-tray');
    const boardWrapEl = document.getElementById('pips-board-wrap');

    let cellCenters = [];
    let cellHitRadius = 0;

    function cacheCellCenters() {
        cellCenters = [];
        let size = 0;
        boardEl.querySelectorAll('.pips-cell').forEach((el) => {
            const idx = Number(el.dataset.cell);
            const r = el.getBoundingClientRect();
            cellCenters[idx] = { x: r.left + r.width / 2, y: r.top + r.height / 2 };
            size = r.width;
        });
        cellHitRadius = size * 1.2;
    }

    function isCellFilled(cell) {
        return document.getElementById('pips-cell-' + cell)?.dataset.filled === '1';
    }

    // Finds the empty-adjacent cell pair (canonical [min, max] index order)
    // whose two centers' own midpoint is closest to (x, y), among pairs
    // within cellHitRadius -- replaces inferring a second cell from drag
    // *direction*, which was confirmed to sometimes land on the wrong cell
    // (especially near an irregular board's own edges/holes, see
    // docs/DEVLOG.md's Pips entry). This answers "which real two-cell
    // footprint does this drop point actually look like it's over,"
    // regardless of exactly how the cursor got there. $ignoreCells lets a
    // domino being relocated treat its own current two cells as available
    // targets too (only ever the relocating domino's own cells, computed
    // from real board state, never trusted from elsewhere).
    function closestValidPair(x, y, ignoreCells = []) {
        const cols = Number(boardWrapEl.dataset.cols);
        const rows = Number(boardWrapEl.dataset.rows);
        let best = null;
        let bestDist = Infinity;

        cellCenters.forEach((center, cell) => {
            if (!center) {
                return;
            }
            if (isCellFilled(cell) && !ignoreCells.includes(cell)) {
                return;
            }

            const row = Math.floor(cell / cols);
            const col = cell % cols;

            // Only right/down -- visits every unordered adjacent pair
            // exactly once (as opposed to also checking left/up, which
            // would revisit the same pair from its other cell).
            for (const [r, c] of [[row, col + 1], [row + 1, col]]) {
                if (r < 0 || r >= rows || c < 0 || c >= cols) {
                    continue;
                }
                const neighbor = r * cols + c;
                const neighborCenter = cellCenters[neighbor];
                if (!neighborCenter || (isCellFilled(neighbor) && !ignoreCells.includes(neighbor))) {
                    continue;
                }

                const midX = (center.x + neighborCenter.x) / 2;
                const midY = (center.y + neighborCenter.y) / 2;
                const dist = Math.hypot(midX - x, midY - y);

                if (dist < bestDist && dist <= cellHitRadius) {
                    bestDist = dist;
                    best = cell < neighbor ? [cell, neighbor] : [neighbor, cell];
                }
            }
        });

        return best;
    }

    // Client-tracked mirror of $wire.selectedDominoId, updated optimistically
    // (see the tray tap handling below) so the board's tap-to-place gate
    // never depends on a Livewire round trip having already resolved.
    let localSelectedDominoId = null;

    // Each tray domino's current rotation step (0-3, a real 90deg-per-tap
    // cycle: 0=horizontal, 1=vertical, 2=horizontal flipped end-for-end,
    // 3=vertical flipped) -- purely client state (orientation only matters
    // at the moment of a *drag* placement, tap-to-place has its own
    // tap-order convention instead), never synced to the server. A domino
    // not in the map is step 0. Survives Livewire re-renders (this Map,
    // unlike a DOM class, isn't touched by morphing); syncRotationVisuals()
    // re-applies the matching CSS classes after any such re-render since
    // morphdom otherwise resets a client-added class back to whatever the
    // server last rendered.
    const rotationSteps = new Map();

    function rotationStep(dominoId) {
        return rotationSteps.get(dominoId) ?? 0;
    }

    // Only 2 of the 4 steps are functionally distinct for placement
    // purposes (closestValidPair() picks the actual cell pair by geometry,
    // not by a preset horizontal/vertical guess) -- flip is what decides
    // which value lands on the pair's own min-index cell.
    function isFlipped(dominoId) {
        return rotationStep(dominoId) >= 2;
    }

    function syncRotationVisuals() {
        trayEl.querySelectorAll('.pips-domino').forEach((el) => {
            const step = rotationStep(el.dataset.dominoId);
            el.classList.toggle('pips-domino-vertical', step === 1 || step === 3);
            el.classList.toggle('pips-domino-flipped', step >= 2);
        });
    }

    if (window.Livewire) {
        Livewire.hook('morph.updated', syncRotationVisuals);
    }

    // --- Dragging a domino out of the tray ---
    // Listens on `document`, not the tray container itself, for the
    // move/up half of the gesture -- the pointer necessarily leaves the
    // tray's own bounding box to reach the board, and a plain child
    // listener stops receiving events the moment that happens. The
    // alternative (Boggle's own `setPointerCapture()`, see its own script
    // block) only helps a gesture that stays within one container the
    // whole time; redirecting capture across two separate containers here
    // would be the tail wagging the dog, so this instead attaches/detaches
    // document-level listeners for the lifetime of one drag.
    let draggingDominoId = null;
    let draggingOriginEl = null;
    let dragStart = null;
    let dragGhostEl = null;

    // Distance a pointer has to travel before a press counts as a real drag
    // rather than a tap -- shared by both the tray-drag and the
    // relocate-drag below, and by each one's own ghost's lazy creation, so
    // a plain tap never flashes a ghost into existence for a single frame.
    const DRAG_THRESHOLD_PX = 10;

    trayEl.addEventListener('pointerdown', (e) => {
        if ($wire.ended) {
            return;
        }
        const dominoEl = e.target.closest('.pips-domino');
        if (!dominoEl) {
            return;
        }
        draggingDominoId = dominoEl.dataset.dominoId;
        draggingOriginEl = dominoEl;
        dragStart = { x: e.clientX, y: e.clientY };
        cacheCellCenters();
        document.addEventListener('pointermove', onTrayDragMove);
        document.addEventListener('pointerup', onTrayDragEnd);
        document.addEventListener('pointercancel', onTrayDragCancel);
        anchorCell = null;
    });

    function onTrayDragMove(e) {
        if (!draggingDominoId) {
            return;
        }

        if (!dragGhostEl && Math.hypot(e.clientX - dragStart.x, e.clientY - dragStart.y) > DRAG_THRESHOLD_PX) {
            // Created lazily on the first real move past the tap threshold,
            // not at pointerdown -- a plain tap never gets far enough to
            // reach here, so it never flashes a ghost into existence. The
            // original tray tile stays in the DOM the whole time (just
            // hidden), so a cancelled/rejected drop "snaps back" for free.
            dragGhostEl = draggingOriginEl.cloneNode(true);
            dragGhostEl.removeAttribute('id');
            dragGhostEl.classList.add('pips-drag-ghost');
            document.body.appendChild(dragGhostEl);
            draggingOriginEl.style.visibility = 'hidden';
        }

        if (dragGhostEl) {
            // Direct property writes, not a CSS transition -- this app's
            // own global `transition: none !important` rule (resources/css/
            // app.css) already forbids animating this, which is the right
            // behavior here anyway: 1:1 cursor tracking, no easing lag.
            dragGhostEl.style.left = e.clientX + 'px';
            dragGhostEl.style.top = e.clientY + 'px';
        }
    }

    function onTrayDragEnd(e) {
        stopTrayDragListening();
        removeDragGhost();

        if (!draggingDominoId) {
            return;
        }
        const dominoId = draggingDominoId;
        draggingDominoId = null;

        const moved = Math.hypot(e.clientX - dragStart.x, e.clientY - dragStart.y) > DRAG_THRESHOLD_PX;
        if (!moved) {
            // A plain tap -- select it, or if it's already selected, rotate
            // it 90deg (still selected). A rotation step of 4 is a full
            // 360deg turn back to how it started, which doubles as
            // "deselect" rather than showing step 0 again as if nothing
            // happened. Derives every state from the two existing trackers
            // rather than a separate explicit enum.
            if (localSelectedDominoId !== dominoId) {
                rotationSteps.delete(dominoId);
                localSelectedDominoId = dominoId;
                anchorCell = null;
                $wire.selectTrayDomino(dominoId);
            } else if (rotationStep(dominoId) + 1 < 4) {
                rotationSteps.set(dominoId, rotationStep(dominoId) + 1);
                syncRotationVisuals();
            } else {
                rotationSteps.delete(dominoId);
                localSelectedDominoId = null;
                $wire.selectTrayDomino(dominoId);
            }
            return;
        }

        const pair = closestValidPair(e.clientX, e.clientY);
        if (pair === null) {
            return;
        }

        const [minCell, maxCell] = pair;
        $wire.placeDomino(dominoId, minCell, maxCell, isFlipped(dominoId));
    }

    function onTrayDragCancel() {
        stopTrayDragListening();
        removeDragGhost();
        draggingDominoId = null;
    }

    function stopTrayDragListening() {
        document.removeEventListener('pointermove', onTrayDragMove);
        document.removeEventListener('pointerup', onTrayDragEnd);
        document.removeEventListener('pointercancel', onTrayDragCancel);
    }

    // Removes the floating clone (if a real drag ever created one) and
    // restores the original tray tile's visibility -- called on every drag
    // end/cancel regardless of whether the drop itself succeeded, so a
    // rejected placement just leaves the original tile sitting back in the
    // tray exactly where it started.
    function removeDragGhost() {
        if (draggingOriginEl) {
            draggingOriginEl.style.visibility = '';
            draggingOriginEl = null;
        }

        if (dragGhostEl) {
            dragGhostEl.remove();
            dragGhostEl = null;
        }
    }

    // --- Relocating (or rotating) an already-placed domino ---
    // Mirrors the tray's own drag gesture exactly: a plain tap rotates it
    // in place (rotatePlacement()), a real drag past the same threshold
    // picks it up (its own two source cells visually "lifted," ghost built
    // from its own already-rendered pip nodes so it shows real values) and
    // drops it via the same closestValidPair() the tray uses, this time
    // treating the domino's own current cells as available targets too.
    let relocatingDominoId = null;
    let relocatingCells = null;
    let relocateDragStart = null;
    let relocateGhostEl = null;

    function findPlacementCells(dominoId) {
        const cells = [...boardEl.querySelectorAll('.pips-cell[data-filled="1"]')]
            .filter((el) => el.dataset.dominoId === dominoId)
            .map((el) => Number(el.dataset.cell));

        return cells.length === 2 ? cells : null;
    }

    function buildRelocateGhost(cells) {
        const ghost = document.createElement('div');
        ghost.className = 'pips-domino pips-drag-ghost';

        const [cellA, cellB] = cells;
        const pipA = document.getElementById('pips-cell-' + cellA)?.querySelector('.pips-pip');
        const pipB = document.getElementById('pips-cell-' + cellB)?.querySelector('.pips-pip');

        if (pipA) {
            ghost.appendChild(pipA.cloneNode(true));
        }
        const divider = document.createElement('div');
        divider.className = 'pips-domino-divider';
        ghost.appendChild(divider);
        if (pipB) {
            ghost.appendChild(pipB.cloneNode(true));
        }

        return ghost;
    }

    boardEl.addEventListener('pointerdown', (e) => {
        if ($wire.ended) {
            return;
        }
        const cellEl = e.target.closest('.pips-cell');
        if (!cellEl || cellEl.dataset.filled !== '1') {
            return;
        }
        const dominoId = cellEl.dataset.dominoId;
        const cells = dominoId ? findPlacementCells(dominoId) : null;
        if (!cells) {
            return;
        }

        relocatingDominoId = dominoId;
        relocatingCells = cells;
        relocateDragStart = { x: e.clientX, y: e.clientY };
        cacheCellCenters();
        document.addEventListener('pointermove', onRelocateDragMove);
        document.addEventListener('pointerup', onRelocateDragEnd);
        document.addEventListener('pointercancel', onRelocateDragCancel);
        anchorCell = null;
    });

    function onRelocateDragMove(e) {
        if (!relocatingDominoId) {
            return;
        }

        if (!relocateGhostEl && Math.hypot(e.clientX - relocateDragStart.x, e.clientY - relocateDragStart.y) > DRAG_THRESHOLD_PX) {
            relocateGhostEl = buildRelocateGhost(relocatingCells);
            document.body.appendChild(relocateGhostEl);
            relocatingCells.forEach((cell) => {
                document.getElementById('pips-cell-' + cell)?.classList.add('pips-cell-lifted');
            });
        }

        if (relocateGhostEl) {
            relocateGhostEl.style.left = e.clientX + 'px';
            relocateGhostEl.style.top = e.clientY + 'px';
        }
    }

    function onRelocateDragEnd(e) {
        stopRelocateDragListening();

        if (!relocatingDominoId) {
            removeRelocateGhost();
            return;
        }

        const dominoId = relocatingDominoId;
        const oldCells = relocatingCells;
        const moved = Math.hypot(e.clientX - relocateDragStart.x, e.clientY - relocateDragStart.y) > DRAG_THRESHOLD_PX;

        removeRelocateGhost();
        relocatingDominoId = null;
        relocatingCells = null;

        if (!moved) {
            $wire.rotatePlacement(dominoId);
            return;
        }

        const pair = closestValidPair(e.clientX, e.clientY, oldCells);
        if (pair === null) {
            return;
        }

        const [minCell, maxCell] = pair;
        // Preserves the domino's current orientation across the move --
        // whichever value was on the old min-index cell stays on the new
        // min-index cell, rather than silently resetting it.
        const oldMin = Math.min(...oldCells);
        const oldMinValue = Number(document.getElementById('pips-cell-' + oldMin)?.dataset.value);
        const [lo, hi] = dominoId.split('-').map(Number);
        const flip = oldMinValue === hi;

        $wire.movePlacement(dominoId, minCell, maxCell, flip);
    }

    function onRelocateDragCancel() {
        stopRelocateDragListening();
        removeRelocateGhost();
        relocatingDominoId = null;
        relocatingCells = null;
    }

    function stopRelocateDragListening() {
        document.removeEventListener('pointermove', onRelocateDragMove);
        document.removeEventListener('pointerup', onRelocateDragEnd);
        document.removeEventListener('pointercancel', onRelocateDragCancel);
    }

    function removeRelocateGhost() {
        if (relocatingCells) {
            relocatingCells.forEach((cell) => {
                document.getElementById('pips-cell-' + cell)?.classList.remove('pips-cell-lifted');
            });
        }

        if (relocateGhostEl) {
            relocateGhostEl.remove();
            relocateGhostEl = null;
        }
    }

    // --- Tap-to-place on two empty board cells ---
    let anchorCell = null;

    boardEl.addEventListener('pointerup', (e) => {
        if ($wire.ended || localSelectedDominoId === null || relocatingDominoId !== null) {
            return;
        }
        const cellEl = e.target.closest('.pips-cell');
        if (!cellEl || cellEl.dataset.filled === '1') {
            return;
        }
        const cell = Number(cellEl.dataset.cell);

        if (anchorCell === null) {
            anchorCell = cell;
            return;
        }

        if (anchorCell === cell) {
            anchorCell = null;
            return;
        }

        $wire.placeDomino(localSelectedDominoId, anchorCell, cell, false);
        localSelectedDominoId = null;
        anchorCell = null;
    });

    // Keeps the client mirror in sync with the server's own property --
    // covers the cases the optimistic update above doesn't (e.g. server
    // rejects a placement and resets selectedDominoId, or state is
    // restored on a resumed session). Critically, a no-op when the landing
    // value already matches what the client applied optimistically -- a
    // real, confirmed bug otherwise (found live, not just in a slow test):
    // selectTrayDomino()'s own round trip can land *after* the player has
    // already tapped the first of their two placement cells, and this
    // watcher firing on that merely-confirming update would silently wipe
    // out the anchor tap that was already correctly recorded, forcing the
    // player's very next tap to be treated as a fresh first tap instead of
    // completing the placement.
    $wire.$watch('selectedDominoId', (value) => {
        if (value === localSelectedDominoId) {
            return;
        }

        localSelectedDominoId = value;
        anchorCell = null;
    });
</script>
@endscript
