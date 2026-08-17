# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/DEVLOG.md` (under the relevant feature section) and delete it from this file rather than checking it off in place.

Each task below has a permanent ID number, assigned once and never reused or renumbered — even when an earlier task is completed and removed, the rest keep their existing numbers. Reference a task elsewhere as "TODO #N".

The Order of Work list is a separate, ordinary numbered list reflecting current work priority (position 1 = do next). Its position numbers are not task IDs and will drift out of sync with them as tasks are completed or reordered, so each entry's link text names the task's actual ID (e.g. "Task 3: ...").

## Order of Work

1. [Task 8: Add Quartiles](#8-add-quartiles)
2. [Task 2: Two-factor authentication toggle](#2-two-factor-authentication-toggle)
3. [Task 3: Image avatars](#3-image-avatars)
4. [Task 4: Friends activity](#4-friends-activity)
5. [Task 11: Shared puzzle-game infrastructure](#11-shared-puzzle-game-infrastructure)
6. [Task 5: Rename "Home"/"Dashboard"](#5-rename-homedashboard)
7. [Task 10: Spec and build NYT-style puzzle games](#10-spec-and-build-nyt-style-puzzle-games)
8. [Task 13: Privacy policy page](#13-privacy-policy-page)
9. [Task 20: Farkle's dice row over-grows past its intended size on a wide screen](#20-farkles-dice-row-over-grows-past-its-intended-size-on-a-wide-screen)

## Tasks

### 2. Two-factor authentication toggle

`Features::twoFactorAuthentication()` is currently disabled in `config/fortify.php`. Enable it so users can turn on 2FA manually.

### 3. Image avatars

`<x-avatar>` currently renders a generated initial-letter badge. Allow users to upload an image, or integrate Gravatar, as an alternative.

### 4. Friends activity

The original app plan mentioned a friends-activity feed on the dashboard once, but no friends/social-graph table (follow requests? mutual-only follows?) exists anywhere in the schema. Needs an actual design decision — what "friends" means here — before any building starts.

### 5. Rename "Home"/"Dashboard"

The navigation redesign (see `docs/DEVLOG.md`'s "Navigation" section) deliberately deferred this — it only makes sense once the new sidebar's structure clarifies each page's actual role, which it now does. "Home" (`/`, the public game-picker page) and "Dashboard" (`/dashboard`, the signed-in stats/nav hub) still read as near-synonyms. Needs actual new names, not just a decision that they should change.

### 8. Add Quartiles

A solo word-forming puzzle game — select 2-4 letter tiles from a 4x5 grid to build words, with a bonus for finding all 5 four-tile "Quartiles." [`docs/specs/quartiles.md`](specs/quartiles.md) currently only has the rules and scoring table, not a build spec — it doesn't yet address puzzle generation (letter groups, valid-word source), session/state design, or how a single puzzle instance is represented. Needs the same spec-first treatment `docs/specs/clear-the-deck.md` got before that game was built (see `docs/DEVLOG.md`'s Clear the Deck section) before building starts here.

### 10. Spec and build NYT-style puzzle games

Add Wordle, Spelling Bee, Strands, and Connections. All four need daily/random puzzle content and, since there's no licensed puzzle source, that content will most likely need to be AI-generated rather than hand-authored (unlike Best Match's hand-written card set, this isn't a fixed pool — new puzzles are needed continuously). Each game needs its own build spec, written the same skeleton-first way `docs/specs/clear-the-deck.md` was (see `docs/DEVLOG.md`'s Clear the Deck section), before any building starts. Once built, these are also candidates for Task 11's shared puzzle-code/daily-puzzle infrastructure.

### 11. Shared puzzle-game infrastructure

A cross-cutting set of features for the app's puzzle-style games (Boggle/Word Grid, Quartiles once built, and the NYT-style games in Task 10 once built): give each individual puzzle a unique code so it can be shared and replayed identically by someone else; once Friends activity (Task 4) exists, surface that code there too — e.g. a "Challenge a Friend" button at the end of a game, possibly emailing an invite; and add daily puzzles (one fixed puzzle per game per day, distinct from the existing random ones) with an archive of past days' puzzles. Depends on Task 4 for the friend-sharing piece, and on the relevant puzzle games actually existing.

### 13. Privacy policy page

This app has no privacy policy page anywhere. `docs/specs/analytics.md` section 12 has drafted disclosure copy for the analytics system specifically (also reproduced in `docs/DEVLOG.md`'s "Analytics" section) that's ready to use once a real page exists — deliberately not built as part of the analytics work itself, since creating the app's first privacy-policy page is a bigger scope decision (where does it link from, what else does it need to cover) than implementing analytics.

### 20. Farkle's dice row over-grows past its intended size on a wide screen

The same bug already found and fixed in Yahtzee's own `.yahtzee-play .row` (`docs/DEVLOG.md`'s Yahtzee section — a positive `flex-grow` on a `flex: 1 1 auto` grid inflates it, and the aspect-ratio-square dice with it, well past its intended `min(56vw, 42vh, 720px)` cap) is confirmed present in `.farkle-play .row` too: measured live at 1920×1080, it rendered 1600px wide against an intended ~454px cap. Not fixed alongside Yahtzee's own — Farkle's landscape-layout work (see that section) already documented a real, verified *need* for `flex: 1 1 auto` in its own narrow `.farkle-dice-column` context, so the identical `flex: 0 1 auto` swap needs the same live-viewport verification Yahtzee's own fix got (confirm the landscape/portrait narrow-column cases still work, not just the wide-desktop case) before landing it, rather than assumed safe to copy over.

