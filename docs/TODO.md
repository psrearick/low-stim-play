# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/DEVLOG.md` (under the relevant feature section) and delete it from this file rather than checking it off in place.

Each task below has a permanent ID number, assigned once and never reused or renumbered — even when an earlier task is completed and removed, the rest keep their existing numbers. Reference a task elsewhere as "TODO #N".

The Order of Work list is a separate, ordinary numbered list reflecting current work priority (position 1 = do next). Its position numbers are not task IDs and will drift out of sync with them as tasks are completed or reordered, so each entry's link text names the task's actual ID (e.g. "Task 3: ...").

## Order of Work

1. [Task 10: Spec and build NYT-style puzzle games](#10-spec-and-build-nyt-style-puzzle-games)
2. [Task 11: Shared puzzle-game infrastructure](#11-shared-puzzle-game-infrastructure)
3. [Task 13: Analytics — state/region-level geography](#13-analytics--stateregion-level-geography)
4. [Task 14: Analytics — time-on-site and time-per-page](#14-analytics--time-on-site-and-time-per-page)

## Tasks

### 10. Spec and build NYT-style puzzle games

Add Wordle, Pips, Spelling Bee, Strands, and Connections. All need daily/random puzzle content and, since there's no licensed puzzle source, that content will most likely need to be AI-generated rather than hand-authored (unlike Best Match's hand-written card set, this isn't a fixed pool — new puzzles are needed continuously). Each game needs its own build spec, written the same skeleton-first way `docs/specs/clear-the-deck.md` was (see `docs/DEVLOG.md`'s Clear the Deck section), before any building starts. Once built, these are also candidates for Task 11's shared puzzle-code/daily-puzzle infrastructure.

### 11. Shared puzzle-game infrastructure

A cross-cutting set of features for the app's puzzle-style games (Boggle/Word Grid, Word Blocks, and the NYT-style games in Task 10 once built): give each individual puzzle a unique code so it can be shared and replayed identically by someone else (Word Blocks' `PuzzleGenerator` already supports a reproducible `#[Url] $seed`, unused by any UI yet — see `docs/DEVLOG.md`'s "Word Blocks" section); surface that code on the Friends page (see `docs/DEVLOG.md`'s "Friends & friend activity" section) too — e.g. a "Challenge a Friend" button at the end of a game, possibly emailing an invite; and add daily puzzles (one fixed puzzle per game per day, distinct from the existing random ones) with an archive of past days' puzzles. Depends on the relevant puzzle games actually existing.

### 13. Analytics — state/region-level geography

`docs/concerns.md` (2026-08-21): "analytics, include state, not just country, if possible."

The analytics system (`docs/DEVLOG.md`'s "Analytics" section) resolves geography via MaxMind's `GeoLite2-Country` database only (`App\Analytics\Geo\CountryResolver`, `ANALYTICS_GEO_DRIVER=maxmind`) — country-level data has no subdivision/state field. State-level data needs upgrading to `GeoLite2-City` (which does carry subdivisions), which means: a new `analytics:geoip-update` download target, a `CountryResolver` (or new `RegionResolver`) change, and a new `AnalyticsDailyDimension` dimension/schema for state alongside the existing country one.

**Feasibility checked (2026-08-21): the existing `MAXMIND_ACCOUNT_ID`/`MAXMIND_LICENSE_KEY` already has `GeoLite2-City` access, no new signup or paid tier needed** — confirmed with a live authenticated request against `https://download.maxmind.com/geoip/databases/GeoLite2-City/download` (the same endpoint `AnalyticsGeoipUpdate::EDITION` already uses for Country, just with the edition name swapped), which returned MaxMind's normal `302` download redirect rather than a `401`/`403`. GeoLite2's free program bundles Country, City, and ASN editions under one account by design, so this wasn't actually expected to be gated — just verified rather than assumed. Still deferred pending deciding whether the extra dimension is worth the schema/UI addition for a single-owner hobby app's traffic volume — the credentials are no longer the blocker.

### 14. Analytics — time-on-site and time-per-page

`docs/concerns.md` (2026-08-21): "analytics, include how long users stay on the website" and "...on each page, on average, if possible."

Not currently trackable at all — the whole analytics system is single-event-per-pageview (`RecordPageview` middleware/job writes one `AnalyticsEvent` per request, no session/duration concept) by deliberate design: `docs/DEVLOG.md`'s Analytics section states the design goal is fully cookieless, consent-exempt, no client-side storage. Any real time-on-page/time-on-site measurement needs a client-side signal the server can't derive on its own (a heartbeat, `visibilitychange`, or `beforeunload` beacon) — a genuine architecture change, and one that sits in tension with the "no client-side tracking machinery" goal that made this analytics system consent-exempt in the first place. Deferred pending a decision on whether that tradeoff is worth making, and if so what mechanism (beacon vs. periodic ping) and what privacy story to tell about it.

