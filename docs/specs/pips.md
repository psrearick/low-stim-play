# Pips — Build Spec (skeleton)

Status: **not ready to build from.** This is a skeleton laying out the shape a spec
needs, with the open design questions flagged inline. Fill in the open questions, then
this status line can be removed. See `docs/TODO.md` #10.

---

## 1. Core design decision (read first)

Solo, no rooms, no per-seat secrecy problem (same reasoning as every other game in this
task). This is the one game of the five that isn't word-based at all — it's a numeric
logic/constraint puzzle built around a real domino set: a board is divided into
outlined regions, each carrying a constraint on the pips (dots) that end up inside it
(an exact sum, "all equal," "all different," a greater/less-than threshold, or no
constraint at all), and the player places every domino from a tray onto the board,
covering two adjacent cells each, until the whole board is filled without breaking any
region's rule.

Unlike Connections/Strands (`docs/specs/connections.md`/`docs/specs/strands.md`), this
game's content is **not semantic** — there's no theme, no wordplay, no category
knowledge an LLM would meaningfully help with. It's pure combinatorics, which puts it
architecturally closer to Wordle/Spelling Bee than to Connections/Strands: a real
possibility that this fits the existing zero-persistence `DailyPuzzle::seedFor()` model
cleanly, with no LLM call and no new persisted-puzzle table — **if** a fast, purely
procedural generation algorithm exists (see section 3's central open question). That's
the one thing that needs confirming before assuming this game is as low-risk as Wordle;
the generation algorithm itself is a real, non-trivial constraint-puzzle problem even
without any AI content involved.

This is also the first game in this app needing **drag-and-drop tile placement** as its
core interaction (place a two-cell domino onto the board, in either orientation) —
worth flagging as new frontend-interaction territory, distinct from every existing
game's click/tap-to-select model (Boggle's drag-a-path is the closest precedent, but
that's a single continuous gesture across many cells, not picking up and placing
discrete two-cell pieces with a rotation).

## 2. NYT rules → this app's equivalents

The player-facing rules (place every domino, satisfy every region, fill the whole
board) are already fully digital and need no translation. What's open is where a valid,
solvable, single-solution puzzle actually comes from, and how closely this app matches
NYT's own board/tray scale.

## 3. Open questions (must be answered before implementation)

- **Puzzle generation strategy — the central question (see section 1).** The standard
  technique for this class of logic puzzle (the same approach Sudoku/KenKen generators
  use) is **generate the answer first, then derive constraints from it**: fill a board
  with a valid, complete domino placement (every domino from the tray used exactly
  once, covering the whole board), partition the filled board into regions, and set
  each region's constraint to whatever its own already-placed pip values happen to
  satisfy (e.g. a region that landed on 2, 2, 2 gets an "all equal" constraint; one
  that landed on 3, 5 gets an exact-sum-8 or a "greater than 6" constraint). This
  guarantees solvability by construction, avoiding the much harder problem of
  generating constraints first and then searching for whether *any* domino placement
  satisfies them. Confirm this is the intended direction before building anything, since
  it's foundational to the whole generator's shape.
- **Solution uniqueness.** A real, well-made logic puzzle has exactly one valid
  solution reachable by pure deduction, not several equally-valid arrangements a player
  could stumble into by guessing. The generate-the-answer-first approach above doesn't
  guarantee that on its own — a weak set of constraints can leave multiple boards that
  satisfy them. Confirm whether v1 verifies uniqueness (requires an actual constraint
  solver checking for a second solution, meaningfully more work than generation alone)
  or accepts non-unique solutions as a real v1 quality trade-off, similar in spirit to
  the "no frequency data" trade-offs already accepted for Wordle/Word Blocks/Spelling
  Bee's own word-selection, but a more consequential gap here since it can make a
  puzzle feel broken (multiple "valid" answers) rather than merely occasionally
  obscure.
- **Region shape generation.** Regions need to be contiguous, varied in shape, and
  collectively cover the entire board with no gaps — its own sub-problem, separate
  from picking each region's constraint (which the generate-then-derive approach above
  already resolves). Confirm the intended approach (e.g. a randomized flood-fill/
  region-growing pass over the filled board) before implementation.
- **Board size / tray size, and whether they're fixed or vary by puzzle.** NYT ships
  three difficulty tiers per day with presumably different board/tray sizes. Confirm
  whether v1 supports variable sizes at all, or launches with one fixed size and adds
  difficulty tiers later (mirroring how Word Blocks shipped v1 without resumable saves
  and added them afterward, `docs/specs/quartiles.md` section 4a).
- **Multiple daily puzzles.** Unlike every other game in this app (one puzzle per game
  per day), NYT ships three separate Pips puzzles daily (easy/medium/hard). Confirm
  whether `DailyPuzzle`'s "one puzzle per game per day" shape is extended to "one
  *set* of puzzles per day" for this game specifically, or whether v1 ships a single
  daily difficulty and defers the rest.
- **Domino tray composition.** Confirm whether the tray is always a full standard
  double-six set (28 dominoes, every pip pair 0-0 through 6-6) or a puzzle-specific
  subset — this interacts directly with board size, since a full set requires a
  56-cell board to place entirely.
- **Interaction model specifics.** Drag-and-drop plus rotation (a domino can be placed
  horizontally or vertically) needs its own small design pass — click-to-select-then-
  click-to-place-and-rotate is a plausible lower-effort alternative to true drag-and-
  drop, worth deciding explicitly rather than defaulting to whichever is easiest to
  build.
- **Name/trademark check.** "Pips" is NYT's own name for this game, but unlike
  Wordle/Connections/Strands/Spelling Bee, "pips" is also simply the generic,
  long-established word for the dots on a domino or die — a materially weaker
  trademark claim over the word itself, closer to Clear the Deck's own "generic
  mechanic description" self-assessment (`docs/specs/clear-the-deck.md` section 3)
  than to a coined or repurposed name like "Wordle." Still worth the standard check
  before shipping the name as-is, but flagged as the likely lowest-risk case of the
  five.

## 4. Data model (draft once section 3 is answered)

Likely needs: a `GameType::Pips` case; if generation turns out fast enough to run
on-demand (section 3), this could reuse the existing zero-persistence `DailyPuzzle`
model directly the same way Wordle/Spelling Bee do — otherwise (e.g. if uniqueness
verification makes generation too slow to run per-request) it needs the same
persisted-puzzle-table treatment as Connections/Strands, just for a different reason
(generation cost, not LLM non-determinism); `App\Services\Pips\{PuzzleGenerator,
ConstraintDeriver, RegionGrower}` (and a `SolutionChecker` if uniqueness verification is
in scope); a `pages::pips.play` component tracking each cell's placed value (only for
cells actually covered so far — an empty board reveals nothing about the solution),
which dominoes remain in the tray, and each placed domino's position/orientation; a
stats service following the `statsFor()`/`gamesForUser()`/`bestMetrics()` shape.

## 5. Accessibility

Same low-stim system as the rest of the app (see `docs/DEVLOG.md`'s "Site-wide design
system & chrome" section): muted palette, no forced sound, respects
`prefers-reduced-motion`. Drag-and-drop needs a genuinely usable non-drag alternative
(tap-to-select, tap-to-place) for anyone who can't perform a drag gesture — this isn't
optional polish, since drag-and-drop with no fallback would make the entire game
unplayable for a meaningful subset of users, unlike a purely cosmetic animation this
app's existing "respects `prefers-reduced-motion`" rule already covers. Region
constraint symbols (=, ≠, >, <, a number) are already shape/text-based rather than
color-coded, which fits this app's "don't rely on color alone" rule for free — worth
confirming that stays true rather than adding a color-only region-boundary distinction
during implementation.

## 6. Deliverables for v1

TBD once section 3's open questions are settled.

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
