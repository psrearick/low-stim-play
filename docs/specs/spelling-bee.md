# Word Hive (internally "Spelling Bee") — Build Spec

Ships under the display name **"Word Hive."** "Spelling Bee" is NYT Games' trademarked
name — same category as Boggle/Yahtzee/Family Feud/Quartiles/Wordle, all renamed to a
generic display name before shipping (see `docs/DEVLOG.md`'s "Game renaming" section).
Chosen alongside "Letter Hive"/"Pangram" — picked "Word Hive" to keep the hive/hexagon
visual theme without any bee-specific language, which also settles the rank-ladder
naming question below (no "Queen Bee"). Internal identifiers (`GameType::SpellingBee`,
`spelling_bee`/`spelling-bee` routes and DB values) keep the real mechanic name, same
rationale as every other renamed game — none of that is user-facing. See `docs/TODO.md`
#10.

---

## 1. Core design decision (read first)

Solo, untimed, no rooms, no hidden information from a server's perspective — same shape
as Word Blocks (`docs/specs/quartiles.md`) and for the same reason: a Livewire
component instance is already private to its own visitor, so there's no per-seat
secrecy problem to solve here, unlike Wordle's single-secret-answer problem
(`docs/specs/wordle.md` section 1) or Best Match/Clear the Deck's per-seat state.
Every one of the puzzle's findable words is, in principle, discoverable by the player
from the moment the game loads — nothing is ever resolved fresh server-side the way
Wordle's answer is.

The puzzle-generation problem is a close cousin of Word Blocks': pick a **pangram**
candidate (a word using exactly 7 distinct letters) as the letter set, then enumerate
every dictionary word of 4+ letters that can be spelled using *only* those 7 letters
(repeats allowed) and containing whichever one letter is designated the required
center. That enumeration is a pure dictionary-filter problem — no board, no adjacency,
no spatial layout — so this game fits the existing zero-persistence
`DailyPuzzle::seedFor()` model cleanly, with no LLM call and no new persisted-puzzle
table (contrast with Connections/Strands, `docs/TODO.md` #19, which likely need real
semantic content authored by an LLM).

## 2. NYT rules → this app's equivalents

Scoring (4-letter words = 1 point, longer words = 1 point per letter, +7 bonus for a
pangram) is already digital-native and needs no translation — same "kept exactly as
given" treatment `docs/specs/quartiles.md` applied to its own scoring table. What
needed resolving was where the puzzle's 7 letters come from, how far this app's v1
goes in replicating NYT's own polish (rank ladder, shuffle), and — the one thing this
app has to weigh that NYT's own curated answer list already handles — keeping an
off-putting word from ever surfacing as a puzzle's own defining word or a findable
answer.

## 3. Resolved design decisions

- **Puzzle generation strategy: search `storage/app/words.txt` for a pangram, then
  enumerate.** Candidate pangram words (exactly 7 distinct letters) are drawn from
  `words.txt`, filtered through the exclusion pass below *before* one is picked as the
  seed. For the chosen pangram, each of its 7 letters is tried as the candidate center
  letter (in an order drawn from the same seeded `Randomizer`); for a given center,
  every dictionary word of 4+ letters using only those 7 letters (any repeats) and
  containing the center letter is enumerated, reusing `Boggle\Solver::isValidWord()`
  as the leaf check the same way `Quartiles\WordFinder` already does — no second
  dictionary load. The seed pangram itself is always among the results for *any* of
  its 7 letters as center, since it necessarily contains all 7 — so every generated
  puzzle has at least one findable pangram by construction, without needing a separate
  guarantee.
  - **Quality gating, with a retry.** A center letter is only accepted once its
    enumerated word list reaches a minimum size — **20 words**, a tunable constant, not
    a rigid product requirement, chosen the same "settle the exact threshold once real
    puzzles are in hand" way `docs/specs/wordle.md`'s frequency cutoff was. If none of
    a pangram's 7 centers clears that bar, the generator discards it and tries the next
    pangram candidate, consuming more randomness from the same seeded `Randomizer` (so
    a given seed still always produces the same eventual puzzle, matching
    `Quartiles\PuzzleGenerator::attemptUniqueFragments()`'s own retry precedent), up to
    a capped number of attempts before a `RuntimeException` — an even thinner failure
    mode than Word Blocks' "New Game is free" caveat, since this only fails if the
    dictionary itself can't produce *any* qualifying puzzle at all.
- **Word list quality/exclusions: reuse `CommonWordFilter`'s exclusion list, not its
  frequency ranking.** `storage/app/words.txt` has no proper-noun/vulgar-word curation
  the way NYT's own answer list does — a real risk here specifically, since this game
  surfaces *every* valid word as a target the player is meant to find, unlike Wordle/
  Word Blocks where only one word (or five) ever matters. Rather than
  `CommonWordFilter::mostCommon()` (which also ranks by frequency and truncates to a
  limit — wrong shape here, since this game needs the *full* set of words a given
  letter combination supports, not a top-N-by-commonness subset), `CommonWordFilter`
  gains a new **`excluding(array $words): array`** method: drops only words on
  `storage/app/excluded_words.txt`, with no frequency requirement and no limit,
  keeping every other word — including ones absent from the frequency corpus — intact.
  Applied once, to the initial pangram-candidate pool, before a pangram is ever picked:
  since every enumerated answer word is a superset of letters drawn from an
  already-clean pangram, filtering at the candidate stage is sufficient to keep a
  blocked word from ever becoming the puzzle's own defining word *or* turning up in
  its answer list — no second exclusion pass needed over the enumerated results.
- **Rank ladder: included, with new non-bee-themed tier names.** NYT scores players up
  a named ladder (percentage-of-max-possible-points thresholds) — kept for v1, since
  it's central to the game's own sense of progress and NYT's actual point thresholds
  are just game-balance numbers, not text worth avoiding on their own. Tier names are
  new, not reused from NYT (`Beginner` → `Getting Started` → `Making Progress` →
  `Solid` → `Nice` → `Great` → `Amazing` → `Genius`, with `Hive Master` — not "Queen
  Bee" — reserved for finding every word), matching the "Word Hive" rename's own
  reasoning: dropping the bee/hive-specific NYT branding meant dropping every
  bee-themed rank name that leaned on it, not just the game's own title. Percentage
  cutoffs are a tuning detail to settle during implementation, not a blocking decision.
- **Hint system: none in v1.** Matches Word Blocks' own "no in-play hints" v1 scope —
  no structural hints (e.g. "3 words start with R") for the first release.
- **Shuffle: included.** A cosmetic "rearrange the non-center letters" button, same low
  risk/effort as Word Blocks' own Shuffle.
- **Resumable/daily persistence: Word Blocks' shape, not Wordle's.** Since section 1
  already establishes there's no hidden information here at all (unlike Wordle's
  secret-answer problem), this follows `docs/specs/quartiles.md` section 4a directly —
  a nullable `game_sessions.user_id`, the puzzle's `GameSession` row created immediately
  for a signed-in user, auto-resume on revisit, New Game only offered once ended — not
  Wordle's guest-gets-an-early-row-because-nothing-else-can-hide-the-answer treatment,
  which doesn't apply here since nothing about this puzzle is ever secret from the one
  person playing it. Daily wiring matches Task 11's now-generalized shape
  (`docs/DEVLOG.md`'s "Wordle" section is the most recent worked example): `#[Url]
  $dailyDate`, `DailyPuzzle::seedFor()`, one-play-per-day lock for signed-in users on
  today's date specifically, freely replayable for any archived date or as a guest.
- **Name/trademark check.** "Spelling Bee" is NYT's own trademarked name — covered by
  this doc's own opening naming note.

## 4. Data model

- `GameType::SpellingBee` (`spelling_bee`) — kept alongside the existing cases in
  `App\Enums\GameType`, `label()` returns `"Word Hive"`.
- `App\Services\Words\CommonWordFilter::excluding(array $words): array` — new method
  alongside the existing `mostCommon()`, exclusion-only with no frequency requirement
  or limit (section 3). Reusable by any future word game that needs the exclusion list
  without the commonness ranking.
- `App\Services\SpellingBee\PuzzleGenerator` — mirrors `Quartiles\PuzzleGenerator`'s
  seeded `Randomizer`/`Mt19937` pattern for `DailyPuzzle::seedFor()` reproducibility:
  reads/filters `words.txt` for 7-distinct-letter words, excludes via
  `CommonWordFilter::excluding()`, picks a pangram and center letter per section 3's
  quality-gated retry loop, and enumerates the full answer-word list for that
  letter/center pair (needed once, at generation time, for the quality check itself and
  for the eventual reveal/"words you missed" screen — the same "expensive, compute
  once" precedent as Boggle's `solve()`/Word Blocks' `WordFinder`; word validity for a
  live submission is a single dictionary-containment-plus-letter-membership check, no
  enumeration needed at play time).
- `App\Services\SpellingBee\Scorer`: `pointsFor(string $word): int` (1 point for a
  4-letter word, +1 per letter beyond 4, +7 pangram bonus — matches the appendix's
  table exactly) and a `maxPossible(array $words): int` summing every generated
  answer's own score, needed by the rank ladder below.
- `App\Services\SpellingBee\RankCalculator`: given a current score and the puzzle's
  `maxPossible()`, returns the current tier name from section 3's fixed
  percentage-of-max ladder — a small, game-specific lookup, not folded into `Scorer`
  since it's a presentation concern layered on top of the raw point total, not part of
  scoring a single word.
- `pages::spelling-bee.play` (route `spelling-bee.play`, path `/word-hive`):
  `letters` (7, one flagged center), `foundWords` (word → points, same shape as
  Boggle/Word Blocks), `score`, `maxScore` (from generation, needed for `rank`), `rank`
  (computed from `RankCalculator`), `allValidWords` (populated only once the game is
  explicitly ended, same `?array` reveal-time shape as Boggle/Word Blocks). Following
  Word Blocks' section 4a shape: an explicit **End Game** button reveals found vs.
  missed words and records the `GameParticipant` — finding every word doesn't
  auto-end the game, matching Word Blocks' own "the player stops at their own pace"
  precedent.
- `App\Services\Stats\SpellingBeeStatsService`, following the existing `statsFor()`/
  `gamesForUser()`/`bestMetrics()` shape: words found, pangrams found, and **best rank
  reached** compared by tier *position* on the ladder, not raw score — since
  `maxPossible()` varies puzzle to puzzle, two different puzzles' raw scores aren't
  directly comparable the way Wordle's fixed six-guess scale is, but "reached Amazing"
  vs. "reached Genius" is.

## 5. Accessibility

Same low-stim system as the rest of the app (see `docs/DEVLOG.md`'s "Site-wide design
system & chrome" section): muted palette, no forced sound, respects
`prefers-reduced-motion`. The hexagon-hive input layout stays keyboard-typeable (NYT's
own version already supports typing, not just tapping hexagons), so this isn't a
mouse/touch-only interaction. Rank-ladder progress does not rely on color alone to
convey advancement — pair a tier change with its name/an icon, not just a color shift,
same rule already applied to Win/Loss coloring and Word Blocks' found-quartile rows.

## 6. Deliverables for v1

- `GameType::SpellingBee` (`label()` "Word Hive") + `CommonWordFilter::excluding()`.
- `App\Services\SpellingBee\{PuzzleGenerator, Scorer, RankCalculator}`.
- `pages::spelling-bee.play` — hexagon letter input (click or type), center letter
  visually distinct, live found-word list and score, Shuffle, rank-ladder progress
  indicator, End Game with reveal (found vs. missed words). Resumable in-progress games
  for signed-in users (section 3/4a shape): `game_sessions.user_id`, auto-resume, New
  Game only offered once ended. Route `/word-hive`, name `spelling-bee.play`.
- Daily-puzzle wiring matching Task 11's existing shape for Boggle/Word Blocks/Wordle:
  `GameType::isChallengeable()` gains a `SpellingBee` case, `#[Url] $dailyDate` via
  `DailyPuzzle::seedFor()`, one-play-per-day lock for signed-in users on today's date,
  and a new "Word Hive" link column on `pages::daily-puzzles`.
- `pages::profile.spelling-bee.index`/`.show` + `App\Services\Stats\
  SpellingBeeStatsService`, following the existing per-game profile pattern.
- Homepage card, sidebar link ("Word Hive"), `<x-player-stats>` wiring — same as every
  prior game's.
- Test coverage: `CommonWordFilter::excluding()` unit tests (drops only listed words,
  keeps everything else including words absent from the frequency corpus);
  `SpellingBee\{PuzzleGenerator, Scorer, RankCalculator}` unit tests (a hand-built small
  dictionary producing known pangram/center/word-list combinations, the quality-gate
  retry actually retrying on a thin result, an excluded word never appearing as a
  generated pangram or answer, scoring including the pangram bonus, rank thresholds at
  their boundaries); `pages::spelling-bee.play` feature tests (word submission
  accept/reject, pangram bonus, End Game reveal, daily lock behavior, resumable
  in-progress state surviving a reload).

---

## Appendix: mechanic summary (this app's own words, not NYT's copy)

Seven letters are arranged around one required center letter. The player forms words of
four or more letters using only those seven letters — any letter, including the center
one, may be reused any number of times within a single word — and every valid word must
include the center letter. A 4-letter word scores one point; each letter beyond four
adds one more point. A word that uses all seven letters at least once (a "pangram")
earns a bonus on top of its normal letter-count score. There is no move limit and no
clock — the player keeps finding words until they choose to stop.
