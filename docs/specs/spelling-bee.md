# Spelling Bee — Build Spec (skeleton)

Status: **not ready to build from.** This is a skeleton laying out the shape a spec
needs, with the open design questions flagged inline. Fill in the open questions, then
this status line can be removed. See `docs/TODO.md` #10.

---

## 1. Core design decision (read first)

Solo, untimed, no rooms, no hidden information from a server's perspective — same shape
as Word Blocks (`docs/specs/quartiles.md`), and for the same reason: a Livewire
component instance is already private to its own visitor, so there's no per-seat
secrecy problem to solve here at all, unlike Best Match/Clear the Deck.

The puzzle-generation problem is close cousin to Word Blocks', and can reuse the same
overall shape: pick a **pangram** candidate (a word using exactly 7 distinct letters) as
the letter set, then enumerate every dictionary word of 4+ letters that can be spelled
using *only* those 7 letters (repeats allowed) and containing whichever one letter is
designated the required center. That enumeration is a pure dictionary-filter problem —
no board, no adjacency, no spatial layout — which is why this game, like Wordle, fits
the existing zero-persistence `DailyPuzzle::seedFor()` model cleanly, with no LLM call
and no new persisted-puzzle table needed (contrast with Connections/Strands,
`docs/specs/connections.md`/`docs/specs/strands.md`, which likely need real semantic
content authored by an LLM).

## 2. NYT rules → this app's equivalents

Scoring (4-letter words = 1 point, longer words = 1 point per letter, +7 bonus for a
pangram) is already digital-native and needs no translation — same "kept exactly as
given" treatment `docs/specs/quartiles.md` applied to its own scoring table. What's
genuinely open is where the puzzle's 7 letters come from, and how much of NYT's own
polish (rank ladder, hints) this app's v1 replicates.

## 3. Open questions (must be answered before implementation)

- **Puzzle generation strategy.** Confirmed direction to propose: search
  `storage/app/words.txt` for candidate pangram words (exactly 7 distinct letters),
  pick one, then for each of its 7 letters in turn as the candidate center letter,
  enumerate all dictionary words of 4+ letters using only those 7 letters (any
  repeats) and containing that center letter — reusing `Boggle\Solver::isValidWord()`
  as the leaf check the same way `Quartiles\WordFinder` already does, no second
  dictionary load. Needs a decision on **quality gating**: NYT's own puzzles guarantee
  a healthy word count and at least one findable second pangram; a naive random pick
  can produce a thin, unsatisfying set. Confirm whether v1 retries (regenerate on a
  failed quality check, the same "New Game is free" escape hatch Word Blocks used for
  its own uneven-puzzle caveat) and what the minimum acceptable word count/threshold
  is, or whether an uneven puzzle is an accepted v1 trade-off.
- **Word list quality/exclusions.** NYT's Spelling Bee explicitly excludes proper
  nouns, hyphenated words, and vulgar words from its answer list. `storage/app/
  words.txt` is a general dictionary with no such curation and no frequency data —
  same caveat `docs/specs/quartiles.md` already accepted for Word Blocks. Confirm
  whether that same trade-off is accepted here, or whether some filtering pass is
  worth adding before this ships (a bad/embarrassing word turning up as a valid daily
  answer is a real risk this app hasn't had to weigh for its other word games, since
  none of them surface every valid word as a *target* the way this one does).
- **Rank ladder.** NYT scores players up a named ladder (Beginner through Genius, with
  Queen Bee reserved for finding every word) based on percentage-of-maximum-possible
  points. Confirm whether this app replicates that ladder (and under what names — see
  the trademark note below, since "Queen Bee" leans on the bee/hive theme tied to the
  game's own trademarked name) or ships a simpler progress indicator instead.
- **Hint system.** NYT surfaces a few structural hints (e.g. how many words start with
  each letter). Confirm whether v1 includes anything like this or ships without hints,
  matching Word Blocks' own "no in-play hints" v1 scope.
- **Shuffle.** A cosmetic "rearrange the non-center letters" button — low-risk,
  confirm inclusion (likely yes, same as Word Blocks' own Shuffle).
- **Resumable/daily persistence.** Should follow the same pattern Word Blocks already
  built (`docs/specs/quartiles.md` section 4a: `game_sessions.user_id`, auto-resume,
  New Game only offered once ended) rather than needing a fresh design — flagged here
  as a reuse note, not a new open question.
- **Name/trademark check.** "Spelling Bee" is NYT's own trademarked name — needs the
  same rename treatment as Boggle → Word Grid, Quartiles → Word Blocks, etc.
  (`docs/DEVLOG.md`'s "Game renaming" section). Note the dependency this creates: if
  the chosen display name drops the bee/hive theme, "Queen Bee" and any other
  bee-themed rank name above should likely change too, not just the game's own title.

## 4. Data model (draft once section 3 is answered)

Likely needs: a `GameType::SpellingBee` case; `App\Services\SpellingBee\{PuzzleGenerator,
Scorer}` (mirrors `Quartiles\PuzzleGenerator`'s seeded `Randomizer` pattern for
`DailyPuzzle` reproducibility; word validity is a single dictionary-containment check
per submission, no enumeration needed at play time — the full word list from generation
is only needed for the eventual reveal/"words you missed" screen, the same "expensive,
compute once" precedent as Boggle's `solve()`/Word Blocks' `WordFinder`); a
`pages::spelling-bee.play` component holding `letters` (7, one flagged center),
`foundWords`, `score`, and (if section 3 keeps it) a computed `rank`; a stats service
following the `statsFor()`/`gamesForUser()`/`bestMetrics()` shape (words found,
pangrams found, best rank reached).

## 5. Accessibility

Same low-stim system as the rest of the app (see `docs/DEVLOG.md`'s "Site-wide design
system & chrome" section): muted palette, no forced sound, respects
`prefers-reduced-motion`. The hexagon-hive input layout should stay keyboard-typeable
(NYT's own version already supports typing, not just tapping hexagons) so this isn't a
mouse/touch-only interaction. Rank-ladder progress, if kept, should not rely on color
alone to convey advancement.

## 6. Deliverables for v1

TBD once section 3's open questions are settled.

---

## Appendix: mechanic summary (this app's own words, not NYT's copy)

Seven letters are arranged around one required center letter. The player forms words of
four or more letters using only those seven letters — any letter, including the center
one, may be reused any number of times within a single word — and every valid word must
include the center letter. A 4-letter word scores one point; each letter beyond four
adds one more point. A word that uses all seven letters at least once (a "pangram")
earns a bonus on top of its normal letter-count score. There is no move limit and no
clock — the player keeps finding words until they choose to stop.
