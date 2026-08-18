# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/DEVLOG.md` (under the relevant feature section) and delete it from this file rather than checking it off in place.

Each task below has a permanent ID number, assigned once and never reused or renumbered — even when an earlier task is completed and removed, the rest keep their existing numbers. Reference a task elsewhere as "TODO #N".

The Order of Work list is a separate, ordinary numbered list reflecting current work priority (position 1 = do next). Its position numbers are not task IDs and will drift out of sync with them as tasks are completed or reordered, so each entry's link text names the task's actual ID (e.g. "Task 3: ...").

## Order of Work

1. [Task 13: Privacy policy page](#13-privacy-policy-page)
2. [Task 22: Farkle: three-of-a-kind 1s should score 300, not 1000](#22-farkle-three-of-a-kind-1s-should-score-300-not-1000)
3. [Task 21: Clear the Deck: a forced pickup should end the turn immediately, not grant an extra turn](#21-clear-the-deck-a-forced-pickup-should-end-the-turn-immediately-not-grant-an-extra-turn)
4. [Task 4: Friends activity](#4-friends-activity)
5. [Task 8: Add Quartiles](#8-add-quartiles)
6. [Task 10: Spec and build NYT-style puzzle games](#10-spec-and-build-nyt-style-puzzle-games)
7. [Task 11: Shared puzzle-game infrastructure](#11-shared-puzzle-game-infrastructure)
8. [Task 23: Farkle: add an optional "Spicy" variant toggle](#23-farkle-add-an-optional-spicy-variant-toggle)

## Tasks

### 4. Friends activity

The original app plan mentioned a friends-activity feed on the dashboard once, but no friends/social-graph table (follow requests? mutual-only follows?) exists anywhere in the schema. Needs an actual design decision — what "friends" means here — before any building starts.

### 8. Add Quartiles

A solo word-forming puzzle game — select 2-4 letter tiles from a 4x5 grid to build words, with a bonus for finding all 5 four-tile "Quartiles." [`docs/specs/quartiles.md`](specs/quartiles.md) currently only has the rules and scoring table, not a build spec — it doesn't yet address puzzle generation (letter groups, valid-word source), session/state design, or how a single puzzle instance is represented. Needs the same spec-first treatment `docs/specs/clear-the-deck.md` got before that game was built (see `docs/DEVLOG.md`'s Clear the Deck section) before building starts here.

### 10. Spec and build NYT-style puzzle games

Add Wordle, Pips, Spelling Bee, Strands, and Connections. All need daily/random puzzle content and, since there's no licensed puzzle source, that content will most likely need to be AI-generated rather than hand-authored (unlike Best Match's hand-written card set, this isn't a fixed pool — new puzzles are needed continuously). Each game needs its own build spec, written the same skeleton-first way `docs/specs/clear-the-deck.md` was (see `docs/DEVLOG.md`'s Clear the Deck section), before any building starts. Once built, these are also candidates for Task 11's shared puzzle-code/daily-puzzle infrastructure.

### 11. Shared puzzle-game infrastructure

A cross-cutting set of features for the app's puzzle-style games (Boggle/Word Grid, Quartiles once built, and the NYT-style games in Task 10 once built): give each individual puzzle a unique code so it can be shared and replayed identically by someone else; once Friends activity (Task 4) exists, surface that code there too — e.g. a "Challenge a Friend" button at the end of a game, possibly emailing an invite; and add daily puzzles (one fixed puzzle per game per day, distinct from the existing random ones) with an archive of past days' puzzles. Depends on Task 4 for the friend-sharing piece, and on the relevant puzzle games actually existing.

### 13. Privacy policy page

This app has no privacy policy page anywhere. `docs/specs/analytics.md` section 12 has drafted disclosure copy for the analytics system specifically (also reproduced in `docs/DEVLOG.md`'s "Analytics" section) that's ready to use once a real page exists — deliberately not built as part of the analytics work itself, since creating the app's first privacy-policy page is a bigger scope decision (where does it link from, what else does it need to cover) than implementing analytics.

### 21. Clear the Deck: a forced pickup should end the turn immediately, not grant an extra turn

Currently, per `docs/DEVLOG.md`'s Clear the Deck section ("Turn resolution is four cases, checked in order"), a pickup — playing a card lower than the center stack's current top, which forces the acting seat to take the entire stack plus their attempted cards into their own hand — is grouped with the wild-Ten and completed-run cases as one of the three outcomes that "grant the same player an extra turn." Only the fourth case (an unremarkable legal play that doesn't complete a set) currently passes the turn to the next seat.

Requested change: a forced pickup should end the current player's turn immediately instead, passing to the next seat the same way an ordinary non-set-completing play already does. This reverses part of the turn-resolution rule the game was originally built against — one of the "design decisions resolved collaboratively before any code was written" listed at the top of that same DEVLOG section — not a bug fix, so treat it as a deliberate rule change to already-shipped, tested turn logic (`playCards()` in `pages::clear-the-deck.play`) rather than a simple tweak. Update the existing turn-resolution test coverage in `tests/Feature/Games/ClearTheDeckRoomTest.php` (which currently asserts a pickup grants an extra turn) to match the new rule, not just add new tests alongside the old, now-wrong ones.

### 22. Farkle: three-of-a-kind 1s should score 300, not 1000

`App\Services\Farkle\Scorer::bestScoreForFace()` currently special-cases a bare three-of-a-kind of 1s at a flat 1000 points (`$threeValue = $face === 1 ? 1000 : $face * 100;`), carried over from the 2026-08-06 scoring correction (`docs/DEVLOG.md`'s Farkle section, "Scoring table was wrong and got corrected"). Change three 1s specifically to score 300 — i.e., the same flat `100`-per-die value already used for one or two 1s (`count < 3`) — while leaving every other face's three-of-a-kind value (three of any face 2–6 = face × 100) and every four/five/six-of-a-kind tier untouched. Both `scoreSelection()` (a manual selection during `rollAgain()`) and `scoreMaximal()` (Bank's auto-detection of every scoring die) share this same private method, so a single change there covers both call sites. Update `tests/Unit/Farkle/ScorerTest.php`'s existing three-1s assertions to the new value rather than leaving them asserting the old one.

This is also a prerequisite for Task 23's Spicy variant, whose own doubling math is written against this corrected 300 value, not the current 1000.

### 23. Farkle: add an optional "Spicy" variant toggle

A new, fully-specified optional Farkle variant, host-configurable as an additional toggle alongside the existing target-score/final-round settings (see `docs/specs/farkle.md`'s own precedent for how those are exposed). Rules as given:

- Each of the 6 dice has one face marked in red — a distinct number, one per die, so there's exactly one red ("spicy") side for each of the numbers 1 through 6 across the six dice.
- Rolling a spicy die doubles the score of whatever scoring combination it's part of. E.g. `[4, 4, spicy 4]` scores 800, not the normal 400 for three 4s.
- Multiple spicy dice within the same combination each double it again: `[4, spicy 4, spicy 4]` scores 1600, not 400.
- A spicy die's doubling applies only to the combination it's rolled as part of in that specific roll — it does not retroactively apply to dice already locked in/frozen from an earlier roll this turn.
- **High Stakes** (a separate, additional toggle, independent of Spicy — can be on or off regardless of whether Spicy itself is enabled): a player may open their turn by rolling the *previous* player's own non-frozen dice, instead of a fresh six. If they score on that first roll, the points are added to the previous player's own turn score (not a fresh turn total), and they may continue rolling if they choose. This inheritance is available even if the previous player ended their turn by banking with all six dice already used, not only if they stopped mid-turn with dice still unused.
- Default target score for a Spicy game is 25,000, not the normal mode's 10,000 (still host-configurable via the existing target-score setting, just a different default when Spicy is on).
- Rolling all six dice as spicy in a single roll is an instant win, regardless of the score reached.

Depends on Task 22 (the plain three-1s scoring fix) landing first — the doubling math above is written against that corrected value. Needs the same spec-first treatment `docs/specs/clear-the-deck.md` got (`docs/DEVLOG.md`'s Clear the Deck section) before building starts: the rules above are already fairly complete, but haven't been reviewed for edge cases the way that spec-writing process caught for Clear the Deck (e.g. how a spicy die interacts with the three six-dice-only special patterns — straight, three pairs, two triplets — or with hot dice re-rolling a fresh six mid-turn).

