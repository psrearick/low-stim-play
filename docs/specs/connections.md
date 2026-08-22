# Connections — Build Spec (skeleton)

Status: **not ready to build from.** This is a skeleton laying out the shape a spec
needs, with the open design questions flagged inline. Fill in the open questions, then
this status line can be removed. See `docs/TODO.md` #10.

---

## 1. Core design decision (read first)

Solo, no rooms, no per-seat secrecy problem (same reasoning as Word Blocks/Spelling Bee:
a Livewire component instance is already private to its own visitor). The genuinely hard
problem here isn't gameplay logic — selecting 4 words, checking them against a group,
tracking mistakes are all trivial — it's **puzzle content**. Sixteen words split into
four thematic groups of four, deliberately overlapping in plausible-but-wrong ways
("red herrings" are the entire point), isn't something this app's existing word list
(`storage/app/words.txt`, a flat dictionary with no semantic/category metadata) can
produce. `docs/TODO.md` #10's own framing already anticipates this: "since there's no
licensed puzzle source, that content will most likely need to be AI-generated rather
than hand-authored."

This makes Connections a meaningfully different kind of build than every other game in
this app so far, including the other four in this task. This app's only existing
LLM-backed service, `App\Services\FamilyFeud\JudgeService`, is a live per-guess *judge*
call (matches a free-text guess against a fixed, human-authored answer list, one call
per guess) — it has no precedent for **generating structured puzzle content** up front
(four categories, four words each, a difficulty ranking, non-overlapping and
unambiguous enough to be solvable, entertaining enough to be worth playing). That's a
different kind of call (one structured-JSON generation per puzzle, not one classification
call per guess) and needs its own design, not a reuse of `JudgeService` as-is.

**This also breaks the zero-persistence `DailyPuzzle::seedFor()` assumption** every
other puzzle game in this app relies on (`app/Services/Puzzles/DailyPuzzle.php`'s own
docblock: no `daily_puzzle` table, a puzzle is just `crc32("{type}-{date}")` fed into a
deterministic generator, recomputable anywhere with zero DB reads). An LLM call is not a
pure function of a seed — the same prompt/seed pair is not guaranteed to produce
identical output twice, especially not stably across months as the underlying model is
upgraded, and this app has no existing mechanism for pinning a specific model version to
a specific past date. **Generated puzzle content likely needs to be generated once and
persisted** (a real `connections_puzzles` table, keyed by date or seed, generated lazily
on first request and cached forever after) rather than regenerated on the fly the way
every other game's `DailyPuzzle` derivation currently works. This is the single most
important open question below, and it's shared with Strands (`docs/specs/strands.md`) —
worth resolving once and having both games reuse the same answer, the same way Best
Match/Clear the Deck shared their per-seat-secrecy pattern.

## 2. NYT rules → this app's equivalents

The core interaction (select 4 of 16, submit, right locks the group in and reveals its
category name, wrong costs one of a fixed mistake budget) is already fully digital and
needs no translation. What's open is entirely about where the 16 words and their
groupings come from, and how much of NYT's own presentation polish to replicate.

## 3. Open questions (must be answered before implementation)

- **Content generation architecture — the central question (see section 1).**
  Confirm: (a) an LLM call generates a full puzzle (4 categories × 4 words, a difficulty
  tier per category, ideally including intentional overlaps/red herrings) as structured
  JSON, similar in spirit to `JudgeService`'s OpenRouter call but generating content
  rather than judging it; (b) generated puzzles are persisted (new table) rather than
  regenerated per-request, since the LLM call itself isn't cheap or deterministic enough
  to call on every page load the way `PuzzleGenerator::generate($seed)` is elsewhere;
  (c) how "today's puzzle" gets created — generated eagerly by a scheduled job ahead of
  the day it's needed, or lazily on the first request that day (with a plan for what a
  slow/failed LLM call does to that first visitor).
- **Content quality/safety validation.** An LLM-generated puzzle needs automated
  checks before it's ever shown to a player: exactly 16 distinct words total (no
  duplicates across categories), no accidental extra valid grouping that makes the
  puzzle ambiguous in a way that wasn't intended, no offensive/inappropriate content.
  Confirm what validation runs before a generated puzzle is accepted, and what happens
  if it fails (regenerate automatically? fall back to a hand-authored backup pool, the
  same "fixed pool" approach Best Match/Family Feud already use for their own content
  — see `docs/TODO.md` #10's own framing of why that approach doesn't scale here, but
  it could still work as an emergency fallback).
- **Mistake limit.** Confirm keeping NYT's own 4-mistakes-and-the-game-ends-revealing-
  the-rest shape, or a different budget.
- **"One away" hint.** NYT surfaces a "One away..." message when exactly 3 of 4
  selected words belong to the same correct group. A nice-to-have, not core to the
  mechanic — confirm whether v1 includes it or defers it.
- **Difficulty color scheme.** NYT's own yellow/green/blue/purple difficulty coding is
  closely tied to the game's visual identity. Confirm whether this app reuses that
  scheme, picks its own (worth considering given this app's low-stim/muted-palette
  design system, and as one more point of visual distance from the trademarked
  original), and how difficulty is conveyed without relying on color alone (this app's
  existing accessibility rule — see section 5).
- **Sharing.** NYT's own colored-square share grid (which cells were guessed in what
  order, no words shown) — confirm whether v1 replicates it or relies on this app's
  existing challenge-link convention (`GameType::isChallengeable()`), noting that a
  "challenge a friend" link is a stronger fit here than for a daily-only game, since a
  generated-and-persisted puzzle already has a stable identity to link to.
- **Name/trademark check.** "Connections" is NYT's own trademarked name for this
  puzzle — needs the same rename treatment as every other game
  (`docs/DEVLOG.md`'s "Game renaming" section), though note it's a more generic English
  word than "Wordle"/"Strands"/"Spelling Bee," which may affect how the risk is
  assessed (same category of judgment call Clear the Deck's own name got under
  `docs/specs/clear-the-deck.md` section 3).

## 4. Data model (draft once section 3 is answered)

Likely needs: a `GameType::Connections` case; a new persisted puzzle-content table
(pending section 3's architecture decision — e.g. `connections_puzzles`: `date`or
`seed`, `categories` JSON `[{name, difficulty, words: [4]}]`, generation metadata);
an `App\Services\Connections\PuzzleGenerator` (LLM-backed, structured-JSON generation
call, plus the validation pass from section 3) distinct in shape from every other
`*Generator` in this app so far, since none of them call out to an LLM for content —
closer in spirit to `JudgeService`'s HTTP call than to `Quartiles\PuzzleGenerator`'s
pure-function `Randomizer`; a `pages::connections.play` component holding grid order,
selected words, solved groups (revealing category name + difficulty only once solved),
mistake count, game-over state; a stats service following the `statsFor()`/
`gamesForUser()`/`bestMetrics()` shape (puzzles solved without a mistake, best
mistake count, categories solved by difficulty).

## 5. Accessibility

Same low-stim system as the rest of the app (see `docs/DEVLOG.md`'s "Site-wide design
system & chrome" section): muted palette, no forced sound, respects
`prefers-reduced-motion`. Difficulty tier must not rely on color alone (this app's
existing rule, already applied to Win/Loss coloring, Word Blocks' found-quartile rows,
and flagged again for Wordle above) — pair each difficulty color with a label or icon.

## 6. Deliverables for v1

TBD once section 3's open questions are settled — notably contingent on the content-
generation architecture decision, which may itself need its own smaller design pass
(prompt design, validation rules, storage schema) before a full deliverables list can be
written with confidence.

---

## Appendix: mechanic summary (this app's own words, not NYT's copy)

Sixteen words are shown in a 4×4 grid. Hidden among them are four groups of four words
each, related by some category (a shared category can be as literal as "types of X" or
as indirect as a wordplay/hidden-word pattern) — deliberately designed so some words
could plausibly belong to more than one group. The player selects any four words and
submits; a correct group is removed from the grid and its category name revealed, while
an incorrect guess costs one of a small, fixed number of allowed mistakes. The puzzle
ends in a win once all four groups are found, or a loss once the mistake budget runs
out, at which point the remaining (unsolved) groups are revealed.
