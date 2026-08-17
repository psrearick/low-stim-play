# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/DEVLOG.md` (under the relevant feature section) and delete it from this file rather than checking it off in place.

Each task below has a permanent ID number, assigned once and never reused or renumbered — even when an earlier task is completed and removed, the rest keep their existing numbers. Reference a task elsewhere as "TODO #N".

The Order of Work list is a separate, ordinary numbered list reflecting current work priority (position 1 = do next). Its position numbers are not task IDs and will drift out of sync with them as tasks are completed or reordered, so each entry's link text names the task's actual ID (e.g. "Task 3: ...").

## Order of Work

1. [Task 12: Verify analytics on the deployed app](#12-verify-analytics-on-the-deployed-app)
2. [Task 19: Fix crash visiting a game's play page for a room whose active session is a different game](#19-fix-crash-visiting-a-games-play-page-for-a-room-whose-active-session-is-a-different-game)
3. [Task 8: Add Quartiles](#8-add-quartiles)
4. [Task 2: Two-factor authentication toggle](#2-two-factor-authentication-toggle)
5. [Task 3: Image avatars](#3-image-avatars)
6. [Task 9: Rename trademark-adjacent game routes/URLs](#9-rename-trademark-adjacent-game-routesurls)
7. [Task 4: Friends activity](#4-friends-activity)
8. [Task 11: Shared puzzle-game infrastructure](#11-shared-puzzle-game-infrastructure)
9. [Task 5: Rename "Home"/"Dashboard"](#5-rename-homedashboard)
10. [Task 10: Spec and build NYT-style puzzle games](#10-spec-and-build-nyt-style-puzzle-games)
11. [Task 7: Update README](#7-update-readme)
12. [Task 13: Privacy policy page](#13-privacy-policy-page)
13. [Task 14: Yahtzee scorecard can still need vertical scrolling on a very short viewport](#14-yahtzee-scorecard-can-still-need-vertical-scrolling-on-a-very-short-viewport)
14. [Task 15: Verify Family Feud/Best Match spectator views against the same no-scroll requirement](#15-verify-family-feudbest-match-spectator-views-against-the-same-no-scroll-requirement)
15. [Task 16: Clear the Deck's current-player highlight is hard to read on a TV](#16-clear-the-decks-current-player-highlight-is-hard-to-read-on-a-tv)
16. [Task 17: Verify Farkle's non-spectator scoreboard font size when cast to a TV](#17-verify-farkles-non-spectator-scoreboard-font-size-when-cast-to-a-tv)
17. [Task 18: Boggle: start a new game without ending the current one](#18-boggle-start-a-new-game-without-ending-the-current-one)

## Tasks

### 2. Two-factor authentication toggle

`Features::twoFactorAuthentication()` is currently disabled in `config/fortify.php`. Enable it so users can turn on 2FA manually.

### 3. Image avatars

`<x-avatar>` currently renders a generated initial-letter badge. Allow users to upload an image, or integrate Gravatar, as an alternative.

### 4. Friends activity

The original app plan mentioned a friends-activity feed on the dashboard once, but no friends/social-graph table (follow requests? mutual-only follows?) exists anywhere in the schema. Needs an actual design decision — what "friends" means here — before any building starts.

### 5. Rename "Home"/"Dashboard"

The navigation redesign (see `docs/DEVLOG.md`'s "Navigation" section) deliberately deferred this — it only makes sense once the new sidebar's structure clarifies each page's actual role, which it now does. "Home" (`/`, the public game-picker page) and "Dashboard" (`/dashboard`, the signed-in stats/nav hub) still read as near-synonyms. Needs actual new names, not just a decision that they should change.

### 7. Update README

`README.md`'s "What's here" section (lines 13-19) only lists Boggle, Yahtzee, and Family Feud — it hasn't been updated for either Farkle ("10,000") or Best Match, both of which have since shipped (see `docs/DEVLOG.md`). Needs a pass to bring it in line with the app's current state.

### 8. Add Quartiles

A solo word-forming puzzle game — select 2-4 letter tiles from a 4x5 grid to build words, with a bonus for finding all 5 four-tile "Quartiles." [`docs/specs/quartiles.md`](specs/quartiles.md) currently only has the rules and scoring table, not a build spec — it doesn't yet address puzzle generation (letter groups, valid-word source), session/state design, or how a single puzzle instance is represented. Needs the same spec-first treatment `docs/specs/clear-the-deck.md` got before that game was built (see `docs/DEVLOG.md`'s Clear the Deck section) before building starts here.

### 9. Rename trademark-adjacent game routes/URLs

Boggle, Yahtzee, and Family Feud's user-visible display names were already renamed for trademark-avoidance ("Word Grid," "Yacht," "Survey Says" — see `docs/DEVLOG.md`'s "Game renaming (trademark avoidance)" section), but that rename **deliberately** left routes, named routes, enum case names/values, PHP class/namespace names, CSS classes, element ids, and tests untouched, reasoning that none of it is user-facing and churning it site-wide would be a large, risky diff for no user-visible benefit. This item proposes revisiting that specific tradeoff for routes/URLs (`/boggle`, `/yahtzee`, `/family-feud`, and the named routes `GameType::roomRoute()`/`playRoute()` map to in `routes/web.php`) — URLs are arguably more "visible" than internal code, since they're shared, bookmarked, and appear in the address bar. Before starting, weigh that against the same risk (broken links, no redirects) the original decision already flagged.

### 10. Spec and build NYT-style puzzle games

Add Wordle, Spelling Bee, Strands, and Connections. All four need daily/random puzzle content and, since there's no licensed puzzle source, that content will most likely need to be AI-generated rather than hand-authored (unlike Best Match's hand-written card set, this isn't a fixed pool — new puzzles are needed continuously). Each game needs its own build spec, written the same skeleton-first way `docs/specs/clear-the-deck.md` was (see `docs/DEVLOG.md`'s Clear the Deck section), before any building starts. Once built, these are also candidates for Task 11's shared puzzle-code/daily-puzzle infrastructure.

### 11. Shared puzzle-game infrastructure

A cross-cutting set of features for the app's puzzle-style games (Boggle/Word Grid, Quartiles once built, and the NYT-style games in Task 10 once built): give each individual puzzle a unique code so it can be shared and replayed identically by someone else; once Friends activity (Task 4) exists, surface that code there too — e.g. a "Challenge a Friend" button at the end of a game, possibly emailing an invite; and add daily puzzles (one fixed puzzle per game per day, distinct from the existing random ones) with an archive of past days' puzzles. Depends on Task 4 for the friend-sharing piece, and on the relevant puzzle games actually existing.

### 12. Verify analytics on the deployed app

`docs/specs/analytics.md`'s own acceptance gate (section 6.1) hasn't actually been exercised outside local dev/tests: run `php artisan analytics:doctor <deployed-url>` against the real Laravel Cloud URL once deployed and confirm the resolved client IP is real (not the load balancer's own address — `bootstrap/app.php`'s `trustProxies(at: '*')` is in place but unverified live). Separately, `MAXMIND_ACCOUNT_ID`/`MAXMIND_LICENSE_KEY` are unset everywhere (the countries panel will read empty until they're set and `analytics:geoip-update` has run at least once), and Laravel Cloud's ephemeral filesystem means the weekly `analytics:geoip-update` schedule alone isn't enough to keep the `.mmdb` present after a fresh deploy — needs either a deploy-step run of that command or the file moved to durable storage (S3) and pulled on boot. `ANALYTICS_ADMIN_EMAILS` also needs setting as a real Laravel Cloud environment variable (only set in the local, gitignored `.env` so far) or the dashboard won't be reachable at all in production. See `docs/DEVLOG.md`'s "Analytics" section for the full context.

### 13. Privacy policy page

This app has no privacy policy page anywhere. `docs/specs/analytics.md` section 12 has drafted disclosure copy for the analytics system specifically (also reproduced in `docs/DEVLOG.md`'s "Analytics" section) that's ready to use once a real page exists — deliberately not built as part of the analytics work itself, since creating the app's first privacy-policy page is a bigger scope decision (where does it link from, what else does it need to cover) than implementing analytics.

### 14. Yahtzee scorecard can still need vertical scrolling on a very short viewport

The tablet/TV-width horizontal-scroll fix (`docs/DEVLOG.md`'s Yahtzee section, "Scorecard needed horizontal scrolling...") only addresses column width. Confirmed live at a very short viewport (1920×750, simulating an aspect-ratio change like AirPlay mirroring to a wider-but-shorter display) that `table.scorecard`'s row-height `cqh` scaling has a `10px` font-size floor, and 17 rows don't fit some short viewports even at that floor, since the dice area above `.card-wrap` still claims a large, currently-fixed share of the vertical space regardless of how little is left over. Would need either shrinking/collapsing the dice-area display for spectators specifically, or a more aggressive row-height floor, to guarantee no vertical scrolling at extreme aspect ratios.

### 15. Verify Family Feud/Best Match spectator views against the same no-scroll requirement

Clear the Deck, Farkle, and Yahtzee's central spectator/TV-cast views were all confirmed (via real seeded rooms and screenshots at several viewports) and fixed where broken — see `docs/DEVLOG.md`'s Clear the Deck/Farkle/Yahtzee sections. Family Feud and Best Match were checked by reading the code only: neither uses the `overflow-y-auto` scrollable-panel pattern the other three had, and both have a structurally different "spectator" shape (Family Feud is two teams, not an enumerable seat list; Best Match is a turn/round-based reveal, not a permanent everyone-at-once panel), so neither showed the same obvious bug smell. Neither got the same seeded-room-plus-screenshot verification the other three did, so this is a code-reading conclusion, not a confirmed clean bill of health — worth an actual screenshot pass at a realistic max player/team count before considering the concern fully closed for every game.

### 16. Clear the Deck's current-player highlight is hard to read on a TV

The current player's tableau is outlined/highlighted in blue (`--accent`, via `.ctd-spectator-seat-active`/`.ctd-opponent-name-active` — see `docs/DEVLOG.md`'s Clear the Deck section for where this is defined), but that blue reads as too light/washed-out when cast to a TV, making it hard to tell at a glance whose turn it is. Needs an actual color-contrast pass (a darker or higher-saturation blue, or a different signal entirely) verified against real TV-viewing conditions, not just a desktop monitor — check whether this shared `--accent` color is used the same way elsewhere in the app before changing it in isolation, since a global color-token change has a much bigger blast radius than a Clear the Deck-only one.

### 17. Verify Farkle's non-spectator scoreboard font size when cast to a TV

Farkle's in-progress scoreboard font was bumped to a real `cqh`-scaled size (up to 36px) specifically for the dedicated spectator view (`docs/DEVLOG.md`'s Farkle section) as part of fixing the no-scroll bug — but that only applies once `$this->isSpectator` is true (no seat at all, or a seated player who's explicitly toggled "Watch only"). A seated player who's actively playing on the same device that's being screen-mirrored/cast to a TV (i.e., hasn't toggled "Watch only") still sees the older, smaller fixed-size scoreboard (22px via the existing `@media (min-width: 900px)` rule, from an earlier `docs/concerns.md` fix). Verify whether 22px is legible enough on an actual TV at real viewing distance; if not, either bump that size too or make "Watch only" more clearly the recommended mode for casting.

### 18. Boggle: start a new game without ending the current one

Add a way to start a brand-new Boggle puzzle without formally ending the current one — e.g. when the current board/puzzle is just uninteresting and shouldn't count toward stats. Needs a real design decision before building: does discarding the current game mean its `GameSession`/`GameParticipant` rows are never written at all (only created once a game is actually finished, unlike today), or are they written but need a way to be excluded from stats after the fact? Where does the new button live relative to the existing "New Game" (which currently *does* end/record the prior game) so the two aren't confusable?

### 19. Fix crash visiting a game's play page for a room whose active session is a different game

**Confirmed reproducible** (root-caused starting from a raw error in `docs/notes.md`, then reproduced live with a real test): visiting an online-room game's play page — any of Farkle, Yahtzee, Family Feud, Best Match, or Clear the Deck, the five games that sync shared state via `GameSession.state` — for a room whose *current* active game session actually belongs to a *different* game throws a 500 (`TypeError: ...hydrateFromState(): Argument #1 ($state) must be of type array, null given`). Confirmed with a room switched to Boggle specifically (Boggle's own `GameSession` never populates `state` at all — it only uses `settings.seed`, see `pages::boggle.room`), but the same root cause would also corrupt-read (not necessarily throw the identical error, but still break) any of the other four games' state shape if visited while a *different one* of those four is actually active.

**Root cause**: each of the five pages' `mount()` fetches `$room->gameSession` and unconditionally calls `hydrateFromState($session->state)` without ever checking that `$session->type` actually matches the page's own game.

**How this is reached in practice**: a stale bookmark/browser tab left open to one game's play URL from before the host switches the room to a different game (`docs/DEVLOG.md`'s Room system section, `switchGame()`), or simply typing/pasting the wrong game's play URL with a `?room=` for a room that's actively playing something else.

**Fix direction**: each affected page's `mount()` should verify `$session->type` matches its own `GameType` before calling `hydrateFromState()`, and treat a mismatch the same way as "no active game here" — redirect to the room hub (`route('room.show', ['code' => ...])`) instead of crashing, matching the same "always land somewhere truthful" principle the room-hub work already established (see `docs/DEVLOG.md`'s Room system section, "Advancing from one game to the next").
