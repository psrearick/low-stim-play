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
- **Board/tray size: three difficulty tiers, shipped in v1.** Superseding an earlier
  draft of this section that deferred tiers to a v2 addendum — revisited before build
  and decided in favor of matching NYT's own Easy/Medium/Hard split from the start,
  rather than bolting it on afterward. Each tier gets its own domino-set ceiling and a
  cell count that exactly fits that set (`2 × dominoCount()`):

  | Tier   | Domino set  | Dominoes | Cells | Bounding box |
  |--------|-------------|----------|-------|--------------|
  | Easy   | double-four | 15       | 30    | 6 × 8        |
  | Medium | double-five | 21       | 42    | 7 × 9        |
  | Hard   | double-six  | 28       | 56    | 8 × 10       |

  `App\Enums\PipsDifficulty` (`Easy|Medium|Hard`) is the single source of truth for a
  tier's `rows()`/`cols()`/`maxPip()` — every generation-time service takes these as
  parameters rather than hardcoding the Hard tier's numbers. Applies to both free play
  (three separate entry points/buttons, no in-page picker) and the daily puzzle (three
  independent daily puzzles a day, one persisted row and one one-play-per-day lock per
  tier — see section 4).
- **Board shape: an irregular, grown polyomino within a bounding box, not a filled
  rectangle — added after an initial ship, once screenshots of the real NYT game made
  clear how far a dense rectangle actually diverged from it.** `rows()`/`cols()` above
  are a deliberately oversized *bounding box* (roughly 1.4-1.6× the tier's own cell
  count in area), not the board itself: `App\Services\Pips\ShapeGrower` grows a
  connected, checkerboard-balanced shape of exactly that tier's cell count within the
  box (randomized frontier growth from a central seed cell, the same technique
  `RegionGrower` already used for regions, just for one shape instead of many), then
  `BoardFiller` tiles *that* shape rather than a dense rectangle. The grown shape's own
  tight bounding box (re-indexed to 0-based, always ≤ the tier's own box) is what's
  actually persisted and rendered — it can contain an enclosed interior hole (matching
  the real game's own occasional "picture frame" layouts), which is a real, valid
  outcome of the growth, not a bug. See `docs/DEVLOG.md`'s Pips entry for why this grows
  shape-then-tiles rather than growing both together.
- **Domino tray composition: a full double-N set per tier**, where N is that tier's
  `maxPip` (4/5/6 above) — every pip pair `0-0` through `N-N`. Hard's set (all 28,
  0-0 through 6-6) matches the physical game's full double-six set exactly; Easy/Medium
  are graduated subsets, not curated selections, so no separate "which subset" logic is
  needed beyond the one `maxPip` parameter.
- **Interaction model: true drag-and-drop, built together with a full tap-to-select/
  tap-to-place alternative — not a drag-first, fallback-later sequencing.** Drag a
  domino from the tray onto the board, dropping it at whichever empty adjacent cell
  pair visually looks closest to the drop point (`closestValidPair()` in the play
  page's own script block — replaced an earlier drag-*direction*-based guess after
  live play surfaced it sometimes landing on the wrong cell, see
  docs/DEVLOG.md's Pips entry); equivalently, tap a domino in the tray to select it,
  tap a starting cell, then tap an adjacent cell to place and orient it (tap order,
  not drag direction, controls orientation on that path). Both interaction paths
  update the same underlying placement state through one Livewire action, so there's
  a single source of truth regardless of which input method a given player uses —
  this isn't optional accessibility polish added after the fact (see section 5), it's
  a first-class second interaction model built at the same time as the drag gesture.
- **Rotating and relocating a domino, added after live play surfaced both as
  missing.** Tapping an already-selected tray domino again cycles it through a real
  90°-per-tap rotation (horizontal → vertical → horizontal-flipped →
  vertical-flipped → deselect on the 5th tap, a full 360°) — a domino is a 1×2
  rectangle, not a square, so a 180°-only flip wasn't enough. Purely client-side
  state, consulted only by a subsequent *drag* placement, since tap-to-place already
  has its own orientation signal (tap order). Once placed, a domino can be tapped to
  rotate it in place (`rotatePlacement()`, swaps its two cells' values) or dragged
  to relocate it elsewhere on the board (`movePlacement()`, one atomic round trip) —
  dragging a placed domino mirrors the tray's own drag gesture exactly, including
  the floating ghost (built from the domino's own already-rendered pip nodes) and
  the same closest-pair drop targeting.
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
  dictionary pick is. Columns: `seed`, `difficulty` (`PipsDifficulty` value — a puzzle is
  keyed by **seed + difficulty together**, `unique(['seed', 'difficulty'])`, since the
  same seed integer can legitimately be requested at any of the three tiers and each
  produces a genuinely different puzzle), `board` (JSON: dimensions, region layout, each
  region's constraint type/value), `solution` (JSON: one valid domino placement
  satisfying every region — used only during generation's uniqueness check and as a
  future "reveal solution" hook, never sent to the client during play, though for a
  different reason than Wordle's answer: nothing here is a secret a player is trying to
  *guess*, so there's no play-time correctness reason to withhold it, only a spoiler
  reason). Generation runs once per seed+difficulty pair, on first request for that
  pair, and is cached in this table from then on — each tier's daily puzzle, and any
  archived past date, is computed once and reused by every subsequent visitor, not
  regenerated per visitor the way Wordle/Word Blocks/Spelling Bee's cheaper generators
  are. `board`'s exact shape (section 3's grown-shape rework): `dimensions` (the grown
  shape's own tight bounding box), `cells` (the flat indices actually part of the board
  — not every index in `[0, rows*cols)`, since the box can contain inactive holes), and
  `regions`, each carrying `cells`, `constraint`, `color` (a `RegionColorer`-assigned
  palette index, 0-5) and `badge` (a nullable `{row, col}` fractional board-space anchor
  for its on-board constraint glyph, null for a `none` constraint) — `color`/`badge` are
  computed once at generation time and persisted, not recomputed per render, so a
  resumed session always shows the same colors/anchors it started with.
- `App\Services\Pips\{PuzzleGenerator, ShapeGrower, RegionGrower, RegionColorer,
  ConstraintDeriver, SolutionSolver}` — `PuzzleGenerator` orchestrates the
  grow-shape → fill → grow-regions → derive-constraints → assign-colors →
  verify-uniqueness loop from section 3 (persisting the result to `pips_puzzles`);
  `ShapeGrower`, `RegionGrower`, `RegionColorer`, and `ConstraintDeriver` are its
  extracted sub-steps; `SolutionSolver` is the uniqueness-checking constraint solver,
  kept separate since it's a genuinely distinct algorithm (search) from generation
  (construction).
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
  `selectedDominoId` (tap-to-select state, section 3), `difficulty` (`PipsDifficulty`,
  resolved from an explicit `#[Url]` query param or defaulted to Medium/resumed from an
  in-progress session — section 3's tiers), a live per-region status derived from
  `ConstraintChecker`, and `ended`/`solved` once the board is fully and validly filled.
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
  and a per-difficulty breakdown (`bestMetricsByDifficulty()`) — part of the v1 shape
  from the start now that section 3 ships three tiers in v1, mirroring the solo/room
  split `BoggleStatsService::bestMetricsByMode()` already established, extended from two
  categories to three.

## 5. Accessibility

Same low-stim system as the rest of the app (see `docs/DEVLOG.md`'s "Site-wide design
system & chrome" section): muted palette, no forced sound, respects
`prefers-reduced-motion`. The tap-to-select/tap-to-place interaction (section 3) is a
first-class second way to play, not a degraded fallback bolted on afterward — anyone who
can't perform a drag gesture gets the exact same placement capability, just via taps
instead. Region constraint symbols (=, ≠, >, <, a number) are already shape/text-based
rather than color-coded, which fits this app's "don't rely on color alone" rule for
free.

Region *fill color* (section 3's `RegionColorer`) is a grouping aid ("which cells belong
together"), not a pass/fail signal — this app already treats that distinction as
color-only-is-fine elsewhere (see `resources/css/app.css`'s own comment on the
`--rival-1..4` participant colors), and this is the same reasoning extended from a
border hue to a full fill. Live per-region satisfied/broken feedback stays
shape/icon-based on top of that fill, not color-only: satisfied overrides the region's
resting dashed border to a solid, thicker, accent-colored one (a real style change) plus
a ✓ glyph; broken keeps the same dashed style (so it never collides with the region's
own always-on dashed grouping border) and tints the fill toward `--warn`, with the ✕
glyph carrying the actual signal and the tint only reinforcing it. A "Satisfied"
verdict is only ever shown once a region is *completely* filled, even though
`ConstraintChecker` itself can resolve a loose greater/less constraint as satisfied
far earlier (e.g. a region whose maximum possible total already stays under a
threshold with zero cells filled) — true, but confusing to show as "correct" on a
region the player hasn't touched at all, so the play page downgrades that specific
case back to Unknown until the region is actually full.

The drag-and-drop gesture's own visual feedback (a floating tile that follows the
cursor) is pointer-driven, not autoplaying motion — it updates only in direct response
to the player's own `pointermove` events, never via a CSS transition/animation (this
app's own global `transition: none !important` rule already forbids that), so it
doesn't conflict with `prefers-reduced-motion`'s intent the way a decorative animation
would.

## 6. Deliverables for v1

- `GameType::Pips` (`label()` "Pips") + `App\Enums\PipsDifficulty` (Easy/Medium/Hard,
  section 3) + new `pips_puzzles` table (migration, keyed by seed+difficulty).
- `App\Services\Pips\{PuzzleGenerator, ShapeGrower, RegionGrower, RegionColorer,
  ConstraintDeriver, SolutionSolver, ConstraintChecker}`, every generation-time class
  parameterized by a tier's
  rows/cols/maxPip rather than hardcoding the Hard tier's numbers.
- `pages::pips.play` — a board of outlined regions sized per the resolved difficulty
  tier, a full double-N domino tray for that tier, drag-and-drop placement with a full
  tap-to-select/tap-to-place alternative (section 3), live per-region satisfied/broken
  feedback, duration-based scoring, End Game. Route `/pips`, name `pips.play`, tier
  chosen via an explicit `#[Url] $difficulty` query param (three separate entry points —
  homepage buttons, daily-puzzle links — rather than an in-page picker).
- Daily-puzzle wiring matching Task 11's existing shape for Boggle/Word Blocks/Wordle,
  adapted for this game's persisted-puzzle *and* three-tier model (section 4):
  `GameType::isChallengeable()` gains a `Pips` case, `#[Url] $dailyDate` +
  `#[Url] $difficulty` together resolve a seed via `DailyPuzzle::seedFor(GameType::Pips,
  $date, $difficulty->value)` (that method's optional third `$variant` parameter, added
  for this) which looks up (generating and caching on first request) the matching
  `pips_puzzles` row, an independent one-play-per-day lock per tier for signed-in users
  on today's date, and a new "Pips" link column (containing all three tiers' links) on
  `pages::daily-puzzles`.
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
