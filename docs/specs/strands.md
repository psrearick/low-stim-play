# Strands — Build Spec (skeleton)

Status: **not ready to build from.** This is a skeleton laying out the shape a spec
needs, with the open design questions flagged inline. Fill in the open questions, then
this status line can be removed. See `docs/TODO.md` #10.

---

## 1. Core design decision (read first)

Solo, no rooms, no per-seat secrecy problem (same reasoning as Word Blocks/Spelling
Bee/Connections). This is likely **the hardest of the five games in this task**,
because it has two hard problems stacked on top of each other rather than one:

1. **Semantic content**, the same problem Connections (`docs/specs/connections.md`)
   has and this app has no existing precedent for solving (see that spec's section 1):
   a theme, a set of theme words that fit it, and one "spangram" word/phrase that
   both fits the theme *and* is constrained to touch two opposite edges of the board
   once placed.
2. **A genuinely new spatial-fitting problem this app hasn't had to solve before.**
   Boggle's grid is randomly filled dice — nothing is placed *to* spell anything.
   Word Blocks has no adjacency constraint at all — any subset of tiles in any order
   is legal. Strands needs the opposite of both: every one of the theme words (plus
   the spangram) must be placed into a fixed-size letter grid such that each word
   traces a legal path (adjacent cells, in this app's existing Boggle sense of
   8-directional/king-move adjacency — `Boggle\Solver`'s own adjacency rule is
   directly reusable here, see section 3) with **every single grid cell used by
   exactly one word, no leftover letters, and no overlap** — and the spangram's own
   path must additionally start on one edge of the board and end on the opposite one.
   This is a word-search-*construction* problem (the reverse of Boggle's own solver),
   and unlike a simple dictionary filter, it isn't guaranteed to succeed for an
   arbitrary set of theme words — some length/letter combinations simply won't tile a
   fixed-size grid with valid connected paths, and discovering that requires an actual
   backtracking search, not a lookup.

Both problems need real design decisions before any code gets written — this skeleton
intentionally stops well short of a data model or deliverables list until they're
resolved (see section 3).

## 2. NYT rules → this app's equivalents

The player-facing loop (drag/tap a path through adjacent letters, theme words highlight
blue, the spangram highlights yellow, the board is fully covered once every word is
found) is already digital-native. What's open is entirely upstream of that: how a valid
board gets built in the first place, and how much of NYT's own hint economy to
replicate.

## 3. Open questions (must be answered before implementation)

- **Content + grid-fitting generation strategy — the central question (see section
  1).** Two sub-decisions, likely best made together:
  - **Where the theme/words/spangram come from.** Following `docs/TODO.md` #10's own
    framing (AI-generated content, since no fixed licensed pool exists), the likely
    direction is an LLM call generating a theme, a spangram, and a set of theme words —
    but unlike Connections, the LLM can't just pick *any* words; it needs to pick a set
    whose combined letter count matches the grid's cell count exactly, which an LLM
    can't reliably guarantee on its own. Confirm whether the LLM is asked to also
    account for total length (likely unreliable) versus a second, deterministic
    filtering/retry pass on the app's own side that accepts or rejects a generated
    word set based on whether it sums to the right length before ever attempting to
    place it.
  - **How the accepted word set actually gets placed into the grid.** Needs an actual
    placement algorithm (backtracking search: try to route each word through unused
    cells via legal adjacency, in some order, backtracking on dead ends), plus a
    decision on what happens when placement fails for an otherwise-good word set
    (retry with newly generated content, the same "regeneration is free" escape hatch
    Word Blocks/Spelling Bee both lean on above) — and a real risk that some valid-
    looking word sets are simply untileable, which the design needs to accept as a
    normal, expected outcome of generation, not a bug.
  Both halves need to be resolved together, since the LLM-content half is only useful
  if the app can reliably turn its output into a real board.
- **Same daily-determinism-vs-generation-cost question as Connections, and likely the
  same answer.** An LLM call plus a backtracking placement search is not something to
  run fresh on every page load the way `DailyPuzzle::seedFor()`'s existing pure-
  function generators are — this almost certainly needs a persisted puzzle-content
  table, generated once and cached, the same architectural break from the zero-
  persistence model that Connections needs (see that spec's section 1). Worth
  resolving once, shared by both specs, rather than deciding it twice.
- **Grid size.** NYT's own board is a fixed 6×8 (48 letters). Confirm whether this app
  matches that exactly, or lets grid size vary with however many letters the generated
  word set actually sums to (simpler for the placement algorithm, since it removes one
  more constraint, but means the board isn't a fixed shape across puzzles the way
  every other game's board/grid is).
- **Adjacency rule.** Propose reusing `Boggle\Solver`'s existing 8-directional
  adjacency definition directly rather than inventing a new one — flagged here mainly
  to confirm rather than as a genuinely open design choice.
- **Hint system.** NYT's "find 3 non-theme words of 5+ letters to earn a hint that
  reveals one theme word's letters" is a meaningful piece of the real game's feel.
  Confirm whether v1 includes it, or ships a simpler version first (find every theme
  word and the spangram, no hint economy) with hints added later — the same
  "resumable games" v2-addendum pattern Word Blocks used
  (`docs/specs/quartiles.md` section 4a) to add a feature after a simpler v1 shipped.
- **Non-theme bonus words.** Related to the hint question — confirm whether finding a
  valid non-theme word (using cells that happen to also form some other real word) is
  tracked/rewarded at all in a version without hints, or simply not distinguished from
  an invalid guess.
- **Name/trademark check.** "Strands" is NYT's own trademarked name — needs the same
  rename treatment as every other game (`docs/DEVLOG.md`'s "Game renaming" section).

## 4. Data model (draft once section 3 is answered)

Deliberately left undrafted — both halves of section 1's core problem (content source,
placement algorithm) need to be resolved first, since the answer materially changes the
shape of what needs to be stored (e.g. whether there's a `strands_puzzles` table at
all, whether it stores just the accepted word list or the full resolved grid + each
word's cell path, and whether a `App\Services\Strands\GridPlacer`-style backtracking
service is even the chosen approach). Once resolved, expect something in the shape of:
a `GameType::Strands` case; a persisted puzzle-content table (grid letters, each theme
word's cell path, the spangram's path, theme); an
`App\Services\Strands\{ThemeGenerator, GridPlacer}` pair; a `pages::strands.play`
component tracking found theme words, the found spangram, and (if kept) hint state; a
stats service following the existing `statsFor()`/`gamesForUser()`/`bestMetrics()`
shape.

## 5. Accessibility

Same low-stim system as the rest of the app (see `docs/DEVLOG.md`'s "Site-wide design
system & chrome" section): muted palette, no forced sound, respects
`prefers-reduced-motion`. The spangram/theme-word color distinction (yellow vs. blue in
NYT's own version) must not rely on color alone (this app's existing rule, applied
throughout this task's other specs above) — needs a secondary visual cue distinguishing
the spangram from an ordinary theme word.

## 6. Deliverables for v1

TBD once section 3's open questions are settled. Given the size of the open generation
problem here, consider whether this game is better sequenced *after* Wordle, Spelling
Bee, and Pips (`docs/specs/wordle.md`, `docs/specs/spelling-bee.md`,
`docs/specs/pips.md`) — none of which need new LLM-content infrastructure or a
placement/tiling solver — so that whatever generation architecture Connections settles
on first (`docs/specs/connections.md`) can be reused rather than designed twice.

---

## Appendix: mechanic summary (this app's own words, not NYT's copy)

A grid of letters is shown, sized so that every letter belongs to exactly one hidden
word. The player traces a path from letter to adjacent letter (in any direction,
including diagonally) to spell a word; a correct "theme word" (one that fits the day's
hidden theme) highlights and stays marked as found. One specific word or phrase, the
"spangram," both summarizes the theme itself and is placed so its path touches two
opposite edges of the grid; it highlights differently from an ordinary theme word once
found. The puzzle is solved once every theme word and the spangram have been found —
between them, every letter in the grid is used by exactly one word, with nothing left
over.
