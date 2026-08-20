# Word Blocks (internally "Quartiles") — Build Spec

Rules as originally provided are kept verbatim in the [Appendix](#appendix-original-rules)
below. See `docs/TODO.md` #8.

**Ships under the display name "Word Blocks."** "Quartiles" is NYT Games' trademarked
name for this exact mechanic — same category as Boggle/Yahtzee/Family Feud/Apples-to-
Apples, all renamed to a generic display name before shipping (see `docs/DEVLOG.md`'s
"Game renaming" section). Chosen collaboratively the same way those were: proposed
alongside "Word Pieces"/"Combo Words," user picked "Word Blocks." Internal identifiers
(`GameType::Quartiles`, `quartiles`/`word-blocks` routes and DB values) keep the real
mechanic name, same rationale as every other renamed game — none of that is user-facing.

**The in-page term for a found 4-tile group is "Quad," not "Quartile."** Missed on the
first implementation pass — the rename above covers the game's own *name*, but rules
text, HUD labels, and stat labels throughout the play/profile pages still said "Quartile"
verbatim (caught via a `grep` sweep across `resources/views/` and a `assertDontSee` test,
not proactively). "Quad" replaces it everywhere a player can see it (rules copy, the HUD
tile, the found-group label, the reveal screen's answer list, both profile pages' stat
labels); this doc's own remaining body text below and the appendix still say
"quartile(s)" freely, matching how `docs/DEVLOG.md` discusses "Boggle" by name — an
internal document isn't user-facing either.

## 1. Core design decision (read first)

Solo only, untimed, no rooms, no hidden information from a server's perspective (unlike
Best Match/Clear the Deck, there's no other player to keep anything secret from — a
Livewire component instance is already private to its own visitor). The genuinely new
problem this game poses, unlike anything else in this app, is **puzzle generation**: a
20-fragment grid has to come from *somewhere*, and unlike Boggle (a fixed physical dice
set) there's no ready-made "correct" board — it has to be built from this app's existing
word list (`storage/app/words.txt`, already loaded for Boggle's own `Solver`).

Two things make this harder than a plain word-search:
- **The grid has structure, not just letters.** 5 of the 20 tiles are pre-designated
  "quartile" groups (4 tiles each, the exact fragments of one dictionary word) — finding
  one is a distinct, tracked event from just spelling any old valid word.
- **"All possible words" needs computing up front, not just validating one guess at a
  time.** Boggle's own reveal screen already does this (`Solver::solve()`, a DFS over
  board adjacency); this game has no adjacency at all — any subset of 1–4 tiles, selected
  in any order, is a legal attempt — so the equivalent enumeration is a subset/permutation
  search instead of a graph walk, reusing `Solver::isValidWord()` as the leaf check but
  needing its own traversal.

## 2. Physical rules → digital equivalents

The appendix's scoring table (1/2/4/8 points for a 1/2/3/4-tile word, +40 for all 5
quartiles) is kept exactly as given — no change needed, it's already digital-native. The
one thing the original rules leave unspecified that a digital version has to decide is
**where the puzzle itself comes from** — resolved in section 3 below.

## 3. Resolved design decisions

- **Puzzle generation: algorithmic, not AI-curated.** Pick 5 distinct random words,
  length 8–16 (so each splits evenly into 4 fragments of 2–4 letters), from
  `storage/app/words.txt` — the same dictionary Boggle's `Solver` already loads. Split
  each into 4 fragments (lengths randomly chosen in `[2,4]` per fragment, summing to the
  word's length), shuffle all 20 fragments into the grid. No new infrastructure (no LLM
  call at puzzle-generation time, unlike Family Feud's live judge calls) — matches what
  the user proposed. **Known caveat, accepted as a v1 trade-off**: the word list carries
  no frequency/commonness data, so an occasional one of the 5 answer words may be
  obscure. Not blocking — "New Game" is free (just regenerates), so a rough puzzle can
  simply be discarded rather than played.
- **Untimed.** Unlike Boggle, no clock — matches the genre (NYT's own Quartiles has no
  timer either) and nothing in the request called for one. Decided independently, not
  re-confirmed with the user — narrow enough to be a judgment call under section 2's
  "pick the simplest faithful digital equivalent" guidance.
- **Selection model: click tiles in any order to build a candidate word, explicit Submit
  button** — not Boggle's drag-and-auto-submit-on-release, since there's no spatial
  adjacency constraint to make a drag path meaningful, and a word can legally be 1, 2, 3,
  or 4 tiles (no natural "release" point to auto-submit at). Clicking an already-selected
  tile removes it from the selection (splicing out at that position, which can change the
  concatenation order of tiles after it) rather than being a no-op — the simplest "toggle"
  interaction given there's no adjacency chain to preserve. Selection caps at 4 tiles;
  a 5th tile click while 4 are already selected is a no-op, mirroring how Boggle's
  `selectCell()` already no-ops a click that isn't legal.
- **A word can be resubmitted from a different set of tiles without being rejected as a
  duplicate word-for-word, but only the first submission of that exact word string ever
  scores** — same "each word counts once" rule Boggle already uses (`foundWords` keyed by
  the word itself). Simpler than trying to decide whether two different tile-paths to the
  same string are "really" different finds.
- **A "Quartile" (the 40-point-bonus kind) is matched by tile-id-set, not by word text.**
  Each of the 5 designated groups is exactly 4 specific tile ids. Submitting a word using
  precisely those 4 ids (any order) marks that group found — moves its tiles to a
  reserved row, distinct background color, and counts toward the 5-quartile bonus. A
  *different* 4-tile combination that happens to also spell a valid dictionary word
  (crossing group boundaries — entirely possible once 20 real dictionary fragments share
  a grid) still scores the normal 8 points for a 4-tile word, but is **not** a
  "Quartile" — it doesn't move tiles, doesn't highlight, and doesn't count toward 5/5.
  This matches how the real mechanic behaves and is the only way "found all 5 quartiles"
  can mean something specific rather than "found five different 4-tile words."
- **Found-quartile tiles remain selectable.** They can still be combined with any other
  tile (found or not) to spell smaller/different words — nothing is ever removed from
  play, a group's tiles are only ever relocated and recolored.
- **Layout**: a fixed 4-column × 5-row grid throughout. Each found quartile permanently
  occupies one full row, filled top-down in the order groups are found; whatever tiles
  remain unfound fill the rows below, freshly shuffled every time a new quartile is
  found. A **Shuffle** button re-shuffles only the still-unfound tiles' positions —
  found-quartile rows never move once placed.
- **End-of-game flow, not an automatic finish.** An explicit **End Game / Submit**
  button (mirrors Boggle's own "End Game," not a forced stop) reveals every valid word
  the puzzle contains (found vs. missed, same shape as Boggle's own reveal screen) and
  records the `GameSession`/`GameParticipant`. Finding all 5 quartiles does **not** end
  the game automatically — it just surfaces a banner ("All 5 quartiles found! N words
  still findable.") and the player keeps playing (or stops) at their own pace, exactly
  as requested. If every valid word has also been found, the banner changes to a plain
  "You found everything!" instead.

## 4. Data model

- `GameType::Quartiles` (`quartiles`) — kept alongside the existing cases in
  `App\Enums\GameType`, `label()` returns `"Word Blocks"`; `roomRoute()`/`playRoute()`
  aren't meaningfully used (no online-room support at all, not even opted out of the way
  Best Match/Clear the Deck are — see section 1) but are implemented pointing at the one
  `quartiles.play` route for interface consistency with every other case.
- No new phase enum — this is solo-only with a single linear "playing → ended" shape,
  the same reason Boggle's own solo mode doesn't have one either.
- `App\Services\Quartiles\PuzzleGenerator` (mirrors `Boggle\BoardGenerator`'s shape,
  including the same seeded `Randomizer`/`Mt19937` pattern for reproducibility, even
  though nothing in v1 actually shares a seed — free architectural consistency, and
  positions this for Task 11's future "puzzle codes" work without a rework):
  reads/filters `storage/app/words.txt` for 8–16 letter words, picks 5 distinct ones,
  splits each into 4 fragments, assigns each fragment a stable `id` (0–19) and
  `groupIndex` (0–4), and returns an initial shuffled tile order.
- `App\Services\Quartiles\WordFinder`: given the 20 tiles' text and an injected
  `Boggle\Solver` (reused as-is for `isValidWord()` — no separate dictionary load),
  recursively enumerates every subset of 1–4 tile ids in every order (≤ 123,520
  permutations for 20 tiles — cheap, same "only computed once, at reveal time" pattern
  as Boggle's own `solve()`), returning `word => max tile-count it can be formed with`.
  Not called during play, only from `endGame()` — real-time submission validity is a
  single `isValidWord()` call on the concatenated selection, no enumeration needed.
- `App\Services\Quartiles\Scorer`: `pointsFor(int $tileCount): int` (1/2/4/8, matching
  the appendix's table exactly) and the `QUARTILE_BONUS = 40` constant.
- `pages::quartiles.play` (route `quartiles.play`, path `/word-blocks`) is the only
  gameplay page — no room/lobby page at all, since there's no multiplayer mode to set up
  (unlike every other game, including solo-capable Boggle). Component state: `tiles`
  (id → `{display, match, groupIndex}`), `tileOrder` (list of 20 ids, current grid
  position order), `selected` (list of tile ids, current in-progress selection),
  `foundWords` (word → points, same shape as Boggle), `foundGroups` (list of group
  indices completed), `score`, `ended`, `allValidWords` (populated only once `ended`,
  same `?array` shape as Boggle's).
- `GameParticipant.stats` on save: `words_found`, `found_words` (word → points),
  `quartiles_found` (0–5), `total_valid_words` (count from `WordFinder`, same "avoid
  recomputing on every profile view" reasoning as Boggle's own `total_valid_words`).
  `score` includes the +40 bonus already applied once earned.
- `App\Services\Stats\QuartilesStatsService`, following the established
  `gamesForUser()`/`statsFor()`/`bestMetrics()` shape (closest to `BoggleStatsService`,
  since both are solo word games): best-metric tiles are Highest score, Most words
  found, and Most quartiles found (omitted if nobody's ever found one, same "omit rather
  than a misleading zero" rule every other stats service already follows).

## 5. Accessibility

Same low-stim system as the rest of the app (see `docs/DEVLOG.md`'s "Site-wide design
system & chrome" section): muted palette, no flashy tile-move/color-change animation (an
instant state swap, not a transition, for the found-quartile row relocation), no forced
sound, respects `prefers-reduced-motion`. Distinct backgrounds for found-quartile rows
must not rely on color alone to convey "found" — pair with a label/icon, same reasoning
already applied to Win/Loss coloring elsewhere in this app.

## 6. Deliverables for v1

- `GameType::Quartiles` (`label()` "Word Blocks").
- `App\Services\Quartiles\{PuzzleGenerator,WordFinder,Scorer}`.
- `pages::quartiles.play` — tile grid, click-to-select + Submit, Shuffle, End Game,
  reveal screen (found vs. missed words, quartile bonus status), New Game. Route
  `/word-blocks`, name `quartiles.play`.
- `pages::profile.quartiles.index`/`.show` + `App\Services\Stats\QuartilesStatsService`,
  following the existing per-game profile pattern. Routes `/profile/word-blocks(...)`,
  names `profile.quartiles`/`profile.quartiles.show`.
- Homepage card, sidebar link ("Word Blocks"), `<x-player-stats>` wiring — same as every
  prior game's.
- Test coverage: `App\Services\Quartiles\PuzzleGenerator`/`WordFinder`/`Scorer` unit
  tests (grid shape, fragment lengths sum correctly, a hand-built small dictionary
  finding known words at known tile-counts); `pages::quartiles.play` feature tests
  (select/toggle/submit, duplicate-word rejection, quartile-group detection by tile-id
  set — including the "different 4 tiles, same word text, not a Quartile" case, shuffle
  only moving unfound tiles, End Game persisting `GameSession`/`GameParticipant`); a
  browser test for the found-quartile row's CSS relocation/recoloring, the bug class
  `CLAUDE.md`'s "Automated Browser Testing" section flags a Livewire-only test can't
  catch.

---

## Appendix: original rules

## How to Play Quartiles

1. **Select tiles** from a 4x5 grid containing 2-4 letters to form words.
2. Combine multiple tiles to create **longer words** for more points.
3. Look for 4-tile words (called **Quartiles**) to earn maximum points.
4. Find all 5 Quartiles in a puzzle to get a **40-point bonus**!

## How do I score points?

Points are awarded based on the length of the words you form. Longer words earn more points, and finding all Quartiles gives you a big **40-point bonus**!

- **1 tile word:** 1 points
- **2 tiles word:** 2 points
- **3 tiles word:** 4 points
- **4 tiles word:** 8 points
