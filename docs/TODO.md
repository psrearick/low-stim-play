# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/DEVLOG.md` (under the relevant feature section) and delete it from this file rather than checking it off in place.

Each task below has a permanent ID number, assigned once and never reused or renumbered — even when an earlier task is completed and removed, the rest keep their existing numbers. Reference a task elsewhere as "TODO #N".

The Order of Work list is a separate, ordinary numbered list reflecting current work priority (position 1 = do next). Its position numbers are not task IDs and will drift out of sync with them as tasks are completed or reordered, so each entry's link text names the task's actual ID (e.g. "Task 3: ...").

## Order of Work

1. [Task 4: Friends activity](#4-friends-activity)
2. [Task 8: Add Quartiles](#8-add-quartiles)
3. [Task 10: Spec and build NYT-style puzzle games](#10-spec-and-build-nyt-style-puzzle-games)
4. [Task 11: Shared puzzle-game infrastructure](#11-shared-puzzle-game-infrastructure)
5. [Task 23: Farkle: add an optional "Spicy" variant toggle](#23-farkle-add-an-optional-spicy-variant-toggle)
6. [Task 24: Move Edit Profile/Log Out into a navbar avatar dropdown, trim the sidebar](#24-move-edit-profilelog-out-into-a-navbar-avatar-dropdown-trim-the-sidebar)

## Tasks

### 4. Friends activity

The original app plan mentioned a friends-activity feed on the dashboard once, but no friends/social-graph table (follow requests? mutual-only follows?) exists anywhere in the schema. Needs an actual design decision — what "friends" means here — before any building starts.

### 8. Add Quartiles

A solo word-forming puzzle game — select 2-4 letter tiles from a 4x5 grid to build words, with a bonus for finding all 5 four-tile "Quartiles." [`docs/specs/quartiles.md`](specs/quartiles.md) currently only has the rules and scoring table, not a build spec — it doesn't yet address puzzle generation (letter groups, valid-word source), session/state design, or how a single puzzle instance is represented. Needs the same spec-first treatment `docs/specs/clear-the-deck.md` got before that game was built (see `docs/DEVLOG.md`'s Clear the Deck section) before building starts here.

### 10. Spec and build NYT-style puzzle games

Add Wordle, Pips, Spelling Bee, Strands, and Connections. All need daily/random puzzle content and, since there's no licensed puzzle source, that content will most likely need to be AI-generated rather than hand-authored (unlike Best Match's hand-written card set, this isn't a fixed pool — new puzzles are needed continuously). Each game needs its own build spec, written the same skeleton-first way `docs/specs/clear-the-deck.md` was (see `docs/DEVLOG.md`'s Clear the Deck section), before any building starts. Once built, these are also candidates for Task 11's shared puzzle-code/daily-puzzle infrastructure.

### 11. Shared puzzle-game infrastructure

A cross-cutting set of features for the app's puzzle-style games (Boggle/Word Grid, Quartiles once built, and the NYT-style games in Task 10 once built): give each individual puzzle a unique code so it can be shared and replayed identically by someone else; once Friends activity (Task 4) exists, surface that code there too — e.g. a "Challenge a Friend" button at the end of a game, possibly emailing an invite; and add daily puzzles (one fixed puzzle per game per day, distinct from the existing random ones) with an archive of past days' puzzles. Depends on Task 4 for the friend-sharing piece, and on the relevant puzzle games actually existing.

### 23. Farkle: add an optional "Spicy" variant toggle

A new, fully-specified optional Farkle variant, host-configurable as an additional toggle alongside the existing target-score/final-round settings (see `docs/specs/farkle.md`'s own precedent for how those are exposed). Rules as given:

- Each of the 6 dice has one face marked in red — a distinct number, one per die, so there's exactly one red ("spicy") side for each of the numbers 1 through 6 across the six dice.
- Rolling a spicy die doubles the score of whatever scoring combination it's part of. E.g. `[4, 4, spicy 4]` scores 800, not the normal 400 for three 4s.
- Multiple spicy dice within the same combination each double it again: `[4, spicy 4, spicy 4]` scores 1600, not 400.
- A spicy die's doubling applies only to the combination it's rolled as part of in that specific roll — it does not retroactively apply to dice already locked in/frozen from an earlier roll this turn.
- **High Stakes** (a separate, additional toggle, independent of Spicy — can be on or off regardless of whether Spicy itself is enabled): a player may open their turn by rolling the *previous* player's own non-frozen dice, instead of a fresh six. If they score on that first roll, the points are added to the previous player's own turn score (not a fresh turn total), and they may continue rolling if they choose. This inheritance is available even if the previous player ended their turn by banking with all six dice already used, not only if they stopped mid-turn with dice still unused.
- Default target score for a Spicy game is 25,000, not the normal mode's 10,000 (still host-configurable via the existing target-score setting, just a different default when Spicy is on).
- Rolling all six dice as spicy in a single roll is an instant win, regardless of the score reached.

The plain three-1s scoring fix this depended on has landed (`docs/DEVLOG.md`'s Farkle section, 2026-08-18) — the doubling math above is written against that corrected 300 value. Needs the same spec-first treatment `docs/specs/clear-the-deck.md` got (`docs/DEVLOG.md`'s Clear the Deck section) before building starts: the rules above are already fairly complete, but haven't been reviewed for edge cases the way that spec-writing process caught for Clear the Deck (e.g. how a spicy die interacts with the three six-dice-only special patterns — straight, three pairs, two triplets — or with hot dice re-rolling a fresh six mid-turn).

### 24. Move Edit Profile/Log Out into a navbar avatar dropdown, trim the sidebar

A follow-up polish pass on the sidebar/navbar split from the "Navigation" redesign (`docs/DEVLOG.md`'s own section) and Task 3's image-avatar upload, both already shipped:

- Drop the " Stats" suffix from each per-game sidebar label (`app-sidebar.blade.php`'s `$links` array) — "Word Grid Stats" → "Word Grid", "Yacht Stats" → "Yacht", "Survey Says Stats" → "Survey Says", "10,000 Stats" → "10,000", "Best Match Stats" → "Best Match", "Clear the Deck (Tens) Stats" → "Clear the Deck (Tens)". The sidebar's own "My Stats" (dashboard) entry is unaffected — it isn't one of the per-game labels this is about.
- Remove the "Edit Profile" link and the "Log Out" form/divider from the sidebar entirely — both move into the new avatar dropdown below instead.
- Add an avatar (the existing `<x-avatar>` component, already used on `/dashboard` and public profile pages) to the persistent top navbar, visible on every authenticated page — including pages with the sidebar (`/dashboard`, `/profile`, the stats pages), which currently render nothing in the top bar's right side at all, unlike the homepage's own `$barRight` slot.
- Clicking the avatar opens a dropdown with three items: "Stats" (→ `/dashboard`), "Edit Profile" (→ `/profile`), "Logout". The existing Rooms menu (`<livewire:rooms-menu>`, `#rooms-menu-toggle`/`#rooms-menu-panel` in `layouts/app.blade.php`, positioning JS in `app.js`) is the established pattern for a toggle-button-plus-positioned-panel in this exact navbar — reuse that interaction shape rather than inventing a new one.
- Remove "My Stats" and "Log Out" (and whatever visual separator currently sits between them) from the homepage's own authenticated-state `$barRight` slot (`welcome.blade.php`) — both are superseded by the new avatar dropdown, which should render there too once it's navbar-wide.
- Guest (unauthenticated) navbar state — Sign In / Create an Account — is unaffected; this is only about the authenticated-user navbar.

