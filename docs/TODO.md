# To Do

Incomplete/deferred items only. Once something here is done, move its writeup into `docs/DEVLOG.md` (under the relevant feature section) and delete it from this file rather than checking it off in place.

Each task below has a permanent ID number, assigned once and never reused or renumbered — even when an earlier task is completed and removed, the rest keep their existing numbers. Reference a task elsewhere as "TODO #N".

The Order of Work list is a separate, ordinary numbered list reflecting current work priority (position 1 = do next). Its position numbers are not task IDs and will drift out of sync with them as tasks are completed or reordered, so each entry's link text names the task's actual ID (e.g. "Task 3: ...").

## Order of Work

1. [Task 19: Spec and build Connections and Strands](#19-spec-and-build-connections-and-strands)
2. [Task 23: Add "Sign in with Google"](#23-add-sign-in-with-google)

## Tasks

### 19. Spec and build Connections and Strands

Split out from former Task 10 (2026-08-23, since completed and removed — see `docs/DEVLOG.md`'s "Word Hive" and "Pips" sections) because both need real semantic content this app has no precedent for generating — its only existing LLM-backed service, `FamilyFeud\JudgeService`, judges a guess against a fixed answer list rather than authoring content — and likely need a persisted puzzle-content table rather than the on-the-fly seed derivation every other puzzle game (including Pips/Word Hive) uses. That's a shared open design question worth resolving once rather than per-game (see either spec's section 1) before writing full build specs. Skeletons exist: [`docs/specs/connections.md`](specs/connections.md), [`docs/specs/strands.md`](specs/strands.md).

Strands additionally needs a new word-placement/grid-tiling solver with no existing precedent in this app (Boggle's `Solver` finds words in a fixed grid; Strands needs to construct a grid that fits a predetermined word list, the reverse problem).

Once built, wire each into the shared puzzle-code/daily-puzzle/Challenge-a-Friend infrastructure the same way Pips and Word Hive did (see `docs/DEVLOG.md`'s "Boggle"/"Word Blocks"/"Word Hive"/"Pips" sections) — Pips' own section is the worked example for a persisted-puzzle model specifically, closer to what Connections/Strands will likely need than Word Hive's zero-persistence shape.

### 23. Add "Sign in with Google"

Moved from `docs/concerns.md` (2026-08-23): "sign in with google."

Fully greenfield — no OAuth package installed (`laravel/socialite` is not in `composer.json`), no `google` entry in `config/services.php`, and no provider/`google_id` column on `users`. Fortify (`app/Providers/FortifyServiceProvider.php`) only handles email/password, 2FA, and passkeys — it has no social-login concept, so this doesn't hook into it.

Needs: `composer require laravel/socialite`; a `google` block in `config/services.php` (`client_id`, `client_secret`, `redirect`) plus an actual Google Cloud OAuth app/credentials; new redirect/callback routes and a controller (Socialite has no Fortify-style scaffolding for this); and a migration adding a nullable `google_id` (or a small `provider`/`provider_id` pair) to `users`, plus a decision for the collision case where a Google account's email already matches an existing password-based account (link vs. reject vs. auto-merge).

