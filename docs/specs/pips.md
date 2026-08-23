# Pips — Build Spec

Ships under its own real name, **"Pips."** Unlike Wordle/Word Blocks/Spelling Bee,
"Pips" is not a coined or repurposed NYT brand name so much as the generic,
long-established word for the dots on a domino or die — a materially weaker trademark
claim, closer to Clear the Deck's own "generic mechanic description" self-assessment
(`docs/specs/clear-the-deck.md` section 3) than to a name like "Wordle." Kept as-is for
v1 on that basis; still worth a final trademark check before shipping, but not a
blocking decision the way Wordle/Word Blocks/Spelling Bee's renames were. See
`docs/TODO.md` #10.

---

## 1. Core design decision (read first)

Solo, no rooms, no per-seat secrecy problem (same reasoning as every other game in this
task) — and, resolved below, no *player-facing* secrecy problem either: unlike Wordle's
one hidden answer, a Pips board's correctness is checked live against its own visible
region rules, not against a stored "solution" the client must never see. This is the one
game of the five that isn't word-based at all — it's a numeric logic/constraint puzzle
built around a real domino set: a board is divided into outlined regions, each carrying
a constraint on the pips (dots) that end up inside it (an exact sum, "all equal," "all
different," a greater/less-than threshold, or no constraint at all), and the player
places every domino from a tray onto the board, covering two adjacent cells each, until
the whole board is filled without breaking any region's rule.

Unlike Connections/Strands (`docs/TODO.md` #19), this game's content is **not
semantic** — there's no theme, no wordplay, no category knowledge an LLM would
meaningfully help with. It's pure combinatorics. The generation problem is real and
non-trivial even without any AI content involved, and — per the resolved decision below
to build a real uniqueness solver for v1 — expensive enough that this game does **not**
end up fitting the same zero-persistence `DailyPuzzle::seedFor()` model Wordle/Spelling
Bee use; see section 4.

This is also the first game in this app needing **drag-and-drop tile placement** as its
core interaction, built together with a full tap-to-select/tap-to-place alternative
(section 3) — distinct from every existing game's click/tap-to-select model (Boggle's
drag-a-path is the closest precedent, but that's a single continuous gesture across many
cells, not picking up and placing discrete two-cell pieces with a rotation).

## 2. NYT rules → this app's equivalents

The player-facing rules (place every domino, satisfy every region, fill the whole board)
are already fully digital and need no translation. What needed resolving was where a
valid, solvable, uniquely-solvable puzzle actually comes from, how closely this app
matches NYT's own board/tray/difficulty scale, and how a player interacts with a
two-cell piece that can be placed in either orientation.

## 3. Resolved design decisions

- **Puzzle generation: generate the answer first, then derive constraints from it.**
  The standard technique for this class of logic puzzle (the same approach Sudoku/KenKen
  generators use): fill a board with a valid, complete domino placement (every domino
  from the tray used exactly once, covering the whole board), partition the filled board
  into regions via a randomized flood-fill/region-growing pass, and set each region's
  constraint to whatever its own already-placed pip values happen to satisfy (e.g. a
  region that landed on 2, 2, 2 gets an "all equal" constraint; one that landed on 3, 5
  gets an exact-sum-8 or a "greater than 6" constraint). This guarantees solvability by
  construction, avoiding the much harder problem of generating constraints first and
  searching for whether *any* domino placement satisfies them.
- **Solution uniqueness: verified for v1, via a real constraint solver.** The
  generate-the-answer-first approach above doesn't guarantee a unique solution on its
  own — a weak set of constraints can leave multiple boards that satisfy them, and unlike
  the "no frequency data" trade-offs already accepted for Wordle/Word Blocks/Spelling
  Bee's word selection, a non-unique Pips puzzle can make the whole puzzle feel broken
  (more than one "valid" answer), not merely occasionally obscure. `App\Services\Pips\
  SolutionSolver` searches for a second valid domino placement/region-satisfying
  assignment distinct from the generated one; if it finds one, the generator discards
  the attempt (regrowing regions on the same filled board, or generating a fresh board
  entirely, consuming more randomness from the same seeded `Randomizer` so a given seed
  still always produces the same eventual puzzle — matching
  `Quartiles\PuzzleGenerator::attemptUniqueFragments()`'s own retry precedent) up to a
  capped number of attempts before a `RuntimeException`.
- **Board/tray size: one fixed size for v1, difficulty tiers deferred.** NYT ships three
  difficulty tiers a day with different board/tray sizes; v1 here ships a single fixed
  size and a single daily puzzle, mirroring how Word Blocks itself shipped v1 without
  resumable saves and added them afterward (`docs/specs/quartiles.md` section 4a) —
  variable sizes/multiple daily difficulties are a v2 addendum, not a v1 requirement.
  Combined with the domino-set decision below, the fixed size is **8 columns × 7 rows
  (56 cells)**, sized to exactly fit a full standard set.
- **Domino tray composition: a full standard double-six set.** All 28 dominoes, every
  pip pair 0-0 through 6-6 — matches the physical game exactly and, combined with the
  board-size decision above, requires no separate "which subset" logic for v1.
- **Interaction model: true drag-and-drop, built together with a full tap-to-select/
  tap-to-place alternative — not a drag-first, fallback-later sequencing.** Drag a
  domino from the tray onto the board, with drop position/direction determining
  orientation; equivalently, tap a domino in the tray to select it, tap a starting cell,
  then tap an adjacent cell to place and orient it. Both interaction paths update the
  same underlying placement state through one Livewire action, so there's a single
  source of truth regardless of which input method a given player uses — this isn't
  optional accessibility polish added after the fact (see section 5), it's a first-class
  second interaction model built at the same time as the drag gesture.
- **Multiple daily puzzles: deferred with board/tray size above.** A single daily
  puzzle for v1, not NYT's three-tier easy/medium/hard set.
- **Name/trademark check: kept as "Pips."** Covered by this doc's own opening naming
  note — a materially weaker trademark claim than Wordle/Word Blocks/Spelling Bee's
  coined names, so no rename for v1.

## 4. Data model

- `GameType::Pips` (`pips`) — kept alongside the existing cases in `App\Enums\GameType`,
  `label()` returns `"Pips"` (no rename, section 3).
- **A new persisted `pips_puzzles` table, not the zero-persistence `DailyPuzzle` model
  every other game in this task uses** — the one real architectural divergence this
  game needed, flagged as an open question in the original skeleton and resolved here:
  section 3's uniqueness solver makes generation expensive enough (potentially several
  retried attempts, each involving a full board fill plus a second-solution search) that
  redoing it on every request for the same seed isn't viable the way Wordle's plain
  dictionary pick is. Columns: `seed` (unique), `board` (JSON: dimensions, region
  layout, each region's constraint type/value), `solution` (JSON: one valid domino
  placement satisfying every region — used only during generation's uniqueness check
  and as a future "reveal solution" hook, never sent to the client during play, though
  for a different reason than Wordle's answer: nothing here is a secret a player is
  trying to *guess*, so there's no play-time correctness reason to withhold it, only a
  spoiler reason). Generation runs once per seed, on first request for that seed, and is
  cached in this table from then on — the daily puzzle, and any archived past date, is
  computed once and reused by every subsequent visitor, not regenerated per visitor the
  way Wordle/Word Blocks/Spelling Bee's cheaper generators are.
- `App\Services\Pips\{PuzzleGenerator, RegionGrower, ConstraintDeriver, SolutionSolver}`
  — `PuzzleGenerator` orchestrates the generate-fill → grow-regions → derive-constraints
  → verify-uniqueness loop from section 3 (persisting the result to `pips_puzzles`);
  `RegionGrower` and `ConstraintDeriver` are its two extracted sub-steps; `SolutionSolver`
  is the uniqueness-checking constraint solver, kept separate since it's a genuinely
  distinct algorithm (search) from generation (construction).
- `App\Services\Pips\ConstraintChecker`: given a region's constraint and whichever of
  its cells are currently filled, reports whether it's already satisfied, already
  broken, or not yet determinable (e.g. an "all equal" region with only one cell filled
  so far can't be judged yet) — drives live per-region visual feedback during play
  (a region can flip to a "broken" state the moment enough of it is filled to prove its
  rule can't hold, without waiting for the entire board). Board-level completion is a
  pure function of `ConstraintChecker` over every region plus "every cell filled" — not
  a comparison against `pips_puzzles.solution`, since a player's own valid arrangement
  doesn't have to be *the* generated solution, only *a* solution (guaranteed unique by
  section 3, so in practice it will be the same one, but the check itself never compares
  boards directly).
- `pages::pips.play` (route `pips.play`, path `/pips`): `board` (regions/constraints,
  loaded from the puzzle row), `placements` (cell → `{value, dominoId, orientation}`,
  only for cells actually covered so far), `trayRemaining` (domino ids not yet placed),
  `selectedDominoId` (tap-to-select state, section 3), a live per-region status derived
  from `ConstraintChecker`, and `ended`/`solved` once the board is fully and validly
  filled.
- **Scoring**: like Wordle, this game has no natural point total (`GameParticipant.
  score` needs an integer) — resolved as a duration-based score, rewarding a faster
  solve the way Wordle's rewards fewer guesses: elapsed time is measured from the first
  domino placement (not from page load, so reading the rules first isn't penalized) to
  the placement that completes a valid solve, and `score` is a simple decreasing
  function of that duration. The exact formula/scale is a tuning detail to settle during
  implementation, not a blocking decision — an unsolved/abandoned End Game scores `0`,
  matching Wordle's own loss treatment.
- `App\Services\Stats\PipsStatsService`, following the existing `statsFor()`/
  `gamesForUser()`/`bestMetrics()` shape: fastest solve, win rate (solved vs. abandoned),
  and — once section 3's v2 difficulty tiers exist — a per-difficulty breakdown; v1
  itself only has one difficulty, so that breakdown is future work, not part of this
  service's v1 shape.

## 5. Accessibility

Same low-stim system as the rest of the app (see `docs/DEVLOG.md`'s "Site-wide design
system & chrome" section): muted palette, no forced sound, respects
`prefers-reduced-motion`. The tap-to-select/tap-to-place interaction (section 3) is a
first-class second way to play, not a degraded fallback bolted on afterward — anyone who
can't perform a drag gesture gets the exact same placement capability, just via taps
instead. Region constraint symbols (=, ≠, >, <, a number) are already shape/text-based
rather than color-coded, which fits this app's "don't rely on color alone" rule for
free; the live per-region satisfied/broken feedback from `ConstraintChecker` needs the
same treatment (a shape/icon change, not just a background-color shift) when it's
implemented.

## 6. Deliverables for v1

- `GameType::Pips` (`label()` "Pips") + new `pips_puzzles` table (migration).
- `App\Services\Pips\{PuzzleGenerator, RegionGrower, ConstraintDeriver, SolutionSolver,
  ConstraintChecker}`.
- `pages::pips.play` — 8×7 board of outlined regions, a 28-domino tray, drag-and-drop
  placement with a full tap-to-select/tap-to-place alternative (section 3), live
  per-region satisfied/broken feedback, duration-based scoring, End Game. Route `/pips`,
  name `pips.play`.
- Daily-puzzle wiring matching Task 11's existing shape for Boggle/Word Blocks/Wordle,
  adapted for this game's persisted-puzzle model (section 4): `GameType::
  isChallengeable()` gains a `Pips` case, `#[Url] $dailyDate` resolves to a seed via
  `DailyPuzzle::seedFor()` which looks up (generating and caching on first request) the
  matching `pips_puzzles` row, one-play-per-day lock for signed-in users on today's
  date, and a new "Pips" link column on `pages::daily-puzzles`.
- `pages::profile.pips.index`/`.show` + `App\Services\Stats\PipsStatsService`, following
  the existing per-game profile pattern.
- Homepage card, sidebar link ("Pips"), `<x-player-stats>` wiring — same as every prior
  game's.
- Test coverage: `Pips\{RegionGrower, ConstraintDeriver}` unit tests (regions stay
  contiguous and collectively cover the board with no gaps, derived constraints are
  actually satisfied by the board they were derived from); `SolutionSolver` unit tests
  (a hand-built board with a known second solution is correctly rejected, a hand-built
  uniquely-solvable board is correctly accepted); `PuzzleGenerator` unit tests
  (end-to-end: a generated puzzle's own solution satisfies every one of its own
  constraints, retry-on-non-unique actually retries); `ConstraintChecker` unit tests for
  every constraint type (sum, all-equal, all-different, greater/less-than, none) at
  partially-filled, fully-satisfied, and fully-broken states; `pages::pips.play` feature
  tests (placement via the tap-to-select path, illegal-placement rejection, solve
  detection, End Game, daily lock behavior); a browser test for actual drag-and-drop
  placement and the per-region live feedback rendering, the bug class `CLAUDE.md`'s
  "Automated Browser Testing" section flags a Livewire-only test can't catch.

---

## Appendix: mechanic summary (this app's own words, not NYT's copy)

A board is divided into outlined regions; each region carries a rule about the pip
values that end up inside it once filled — either an exact total, "every cell here must
match," "no two cells here may match," a minimum/maximum total, or no rule at all. The
player has a set of domino tiles, each showing two pip counts (0 through 6). Every
domino must be placed flat on the board, covering two adjacent empty cells, until the
entire board is covered and every domino from the set has been used exactly once — with
every region's rule satisfied by whatever pip values ended up inside it. A domino can
straddle two different regions at once, with each of its two ends counting only toward
its own region's rule.
