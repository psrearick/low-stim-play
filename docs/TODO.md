# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/DEVLOG.md` (under the relevant feature section) and delete it from this file rather than checking it off in place.

Each task below has a permanent ID number, assigned once and never reused or renumbered — even when an earlier task is completed and removed, the rest keep their existing numbers. Reference a task elsewhere as "TODO #N".

The Order of Work list is a separate, ordinary numbered list reflecting current work priority (position 1 = do next). Its position numbers are not task IDs and will drift out of sync with them as tasks are completed or reordered, so each entry's link text names the task's actual ID (e.g. "Task 3: ...").

## Order of Work

1. [Task 12: Verify analytics on the deployed app](#12-verify-analytics-on-the-deployed-app)
2. [Task 8: Add Quartiles](#8-add-quartiles)
3. [Task 2: Two-factor authentication toggle](#2-two-factor-authentication-toggle)
4. [Task 3: Image avatars](#3-image-avatars)
5. [Task 9: Rename trademark-adjacent game routes/URLs](#9-rename-trademark-adjacent-game-routesurls)
6. [Task 4: Friends activity](#4-friends-activity)
7. [Task 11: Shared puzzle-game infrastructure](#11-shared-puzzle-game-infrastructure)
8. [Task 5: Rename "Home"/"Dashboard"](#5-rename-homedashboard)
9. [Task 10: Spec and build NYT-style puzzle games](#10-spec-and-build-nyt-style-puzzle-games)
10. [Task 7: Update README](#7-update-readme)
11. [Task 13: Privacy policy page](#13-privacy-policy-page)

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
